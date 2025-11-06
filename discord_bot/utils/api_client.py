"""
Client API pour communiquer avec le backend Bank App
"""

import aiohttp
import logging
from typing import Optional, Dict, Any, List
from config import API_BASE_URL, API_BOT_TOKEN

logger = logging.getLogger(__name__)


class BankAPIClient:
    """Client pour interagir avec l'API Bank App"""
    
    def __init__(self):
        self.base_url = API_BASE_URL
        self.bot_token = API_BOT_TOKEN
        self.session: Optional[aiohttp.ClientSession] = None
    
    async def __aenter__(self):
        """Créer une session lors de l'entrée dans le context manager"""
        self.session = aiohttp.ClientSession()
        return self
    
    async def __aexit__(self, exc_type, exc_val, exc_tb):
        """Fermer la session lors de la sortie du context manager"""
        if self.session:
            await self.session.close()
    
    async def _request(
        self,
        method: str,
        endpoint: str,
        token: Optional[str] = None,
        data: Optional[Dict] = None,
        params: Optional[Dict] = None
    ) -> Dict[str, Any]:
        """Effectue une requête HTTP vers l'API"""
        url = f"{self.base_url}{endpoint}"
        headers = {'Content-Type': 'application/json'}
        
        if token:
            headers['Authorization'] = f'Bearer {token}'
        
        try:
            if not self.session:
                self.session = aiohttp.ClientSession()
            
            async with self.session.request(
                method,
                url,
                headers=headers,
                json=data,
                params=params
            ) as response:
                response_data = await response.json()
                
                if response.status >= 400:
                    logger.error(f"API Error {response.status}: {response_data}")
                    return {
                        'success': False,
                        'error': response_data.get('error', 'Erreur inconnue'),
                        'code': response.status
                    }
                
                return response_data
                
        except aiohttp.ClientError as e:
            logger.error(f"Client error: {e}")
            return {
                'success': False,
                'error': 'Erreur de connexion à l\'API',
                'code': 500
            }
        except Exception as e:
            logger.error(f"Unexpected error: {e}")
            return {
                'success': False,
                'error': 'Erreur inattendue',
                'code': 500
            }
    
    async def get_user_token(self, discord_id: str) -> Optional[str]:
        """Obtient un token JWT pour un utilisateur Discord"""
        response = await self._request(
            'POST',
            '/auth/discord/token',
            data={
                'discord_id': discord_id,
                'bot_token': self.bot_token
            }
        )
        
        if response.get('success'):
            return response.get('access_token')
        
        return None
    
    async def get_accounts(self, token: str) -> Optional[List[Dict]]:
        """Récupère la liste des comptes de l'utilisateur"""
        response = await self._request('GET', '/accounts', token=token)
        
        if response.get('success'):
            return response.get('data', {}).get('comptes', [])
        
        return None
    
    async def get_account_details(self, token: str, account_id: int) -> Optional[Dict]:
        """Récupère les détails d'un compte"""
        response = await self._request('GET', f'/accounts/{account_id}', token=token)
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def get_account_balance(self, token: str, account_id: int) -> Optional[Dict]:
        """Récupère le solde d'un compte"""
        response = await self._request('GET', f'/accounts/{account_id}/balance', token=token)
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def get_account_operations(
        self,
        token: str,
        account_id: int,
        limit: int = 10,
        offset: int = 0
    ) -> Optional[Dict]:
        """Récupère les opérations d'un compte"""
        response = await self._request(
            'GET',
            f'/accounts/{account_id}/operations',
            token=token,
            params={'limit': limit, 'offset': offset}
        )
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def create_operation(
        self,
        token: str,
        compte_id: int,
        type_operation: str,
        montant: float,
        destinataire: Optional[str] = None,
        nature: Optional[str] = None,
        description: Optional[str] = None
    ) -> Dict[str, Any]:
        """Crée une nouvelle opération bancaire"""
        data = {
            'compte_id': compte_id,
            'type_operation': type_operation,
            'montant': montant
        }
        
        if destinataire:
            data['destinataire'] = destinataire
        if nature:
            data['nature'] = nature
        if description:
            data['description'] = description
        
        return await self._request('POST', '/operations', token=token, data=data)
    
    async def search_operations(
        self,
        token: str,
        compte_id: Optional[int] = None,
        type_operation: Optional[str] = None,
        nature: Optional[str] = None,
        destinataire: Optional[str] = None,
        montant_min: Optional[float] = None,
        montant_max: Optional[float] = None,
        date_debut: Optional[str] = None,
        date_fin: Optional[str] = None,
        limit: int = 20
    ) -> Optional[List[Dict]]:
        """Recherche des opérations selon des critères"""
        params = {'limit': limit}
        
        if compte_id:
            params['compte_id'] = compte_id
        if type_operation:
            params['type'] = type_operation
        if nature:
            params['nature'] = nature
        if destinataire:
            params['destinataire'] = destinataire
        if montant_min:
            params['montant_min'] = montant_min
        if montant_max:
            params['montant_max'] = montant_max
        if date_debut:
            params['date_debut'] = date_debut
        if date_fin:
            params['date_fin'] = date_fin
        
        response = await self._request('GET', '/operations/search', token=token, params=params)
        
        if response.get('success'):
            return response.get('data', {}).get('operations', [])
        
        return None
    
    async def get_user_profile(self, token: str) -> Optional[Dict]:
        """Récupère le profil de l'utilisateur"""
        response = await self._request('GET', '/user/profile', token=token)
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def get_user_stats(self, token: str) -> Optional[Dict]:
        """Récupère les statistiques de l'utilisateur"""
        response = await self._request('GET', '/user/stats', token=token)
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def get_discord_link_status(self, token: str) -> Optional[Dict]:
        """Récupère le statut de la liaison Discord"""
        response = await self._request('GET', '/user/discord', token=token)
        
        if response.get('success'):
            return response.get('data')
        
        return None
    
    async def unlink_discord(self, token: str) -> bool:
        """Délie le compte Discord"""
        response = await self._request('DELETE', '/user/discord', token=token)
        return response.get('success', False)
