"""
Commandes pour la gestion des comptes bancaires
"""

import discord
from discord import app_commands
from discord.ext import commands
import logging
from typing import Optional

from utils.api_client import BankAPIClient
from utils.embeds import (
    create_error_embed, create_accounts_embed, 
    create_balance_embed, create_operations_embed, create_stats_embed
)
from config import MSG_NOT_LINKED

logger = logging.getLogger(__name__)


class AccountsCog(commands.Cog):
    """Gestion des comptes bancaires"""
    
    def __init__(self, bot: commands.Bot):
        self.bot = bot
        self.api_client = BankAPIClient()
    
    async def cog_load(self):
        """Appelé lors du chargement du cog"""
        logger.info("AccountsCog chargé")
    
    async def cog_unload(self):
        """Appelé lors du déchargement du cog"""
        if self.api_client.session:
            await self.api_client.session.close()
        logger.info("AccountsCog déchargé")
    
    async def get_token(self, user_id: str) -> Optional[str]:
        """Récupère le token de l'utilisateur"""
        return await self.api_client.get_user_token(user_id)
    
    @app_commands.command(name="accounts", description="Afficher tous vos comptes bancaires")
    async def accounts(self, interaction: discord.Interaction):
        """Commande pour afficher tous les comptes"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Récupérer les comptes
            response = await self.api_client._request('GET', '/accounts', token=token)
            
            if not response.get('success'):
                embed = create_error_embed(
                    "Erreur",
                    response.get('error', 'Impossible de récupérer les comptes')
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            data = response.get('data', {})
            accounts = data.get('comptes', [])
            solde_total = data.get('solde_total', 0)
            
            if not accounts:
                embed = create_error_embed(
                    "Aucun compte",
                    "Vous n'avez aucun compte bancaire actif."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            embed = create_accounts_embed(accounts, solde_total)
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la récupération des comptes: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la récupération des comptes."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="balance", description="Afficher le solde d'un compte")
    @app_commands.describe(compte_id="ID du compte (optionnel, affiche tous les comptes si non spécifié)")
    async def balance(self, interaction: discord.Interaction, compte_id: Optional[int] = None):
        """Commande pour afficher le solde"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Si aucun compte spécifié, afficher tous les comptes
            if compte_id is None:
                response = await self.api_client._request('GET', '/accounts', token=token)
                
                if not response.get('success'):
                    embed = create_error_embed(
                        "Erreur",
                        response.get('error', 'Impossible de récupérer les comptes')
                    )
                    await interaction.followup.send(embed=embed, ephemeral=True)
                    return
                
                data = response.get('data', {})
                accounts = data.get('comptes', [])
                solde_total = data.get('solde_total', 0)
                
                embed = create_accounts_embed(accounts, solde_total)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Récupérer le solde d'un compte spécifique
            balance_data = await self.api_client.get_account_balance(token, compte_id)
            
            if not balance_data:
                embed = create_error_embed(
                    "Erreur",
                    "Compte non trouvé ou vous n'avez pas accès à ce compte."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            embed = create_balance_embed(balance_data)
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la récupération du solde: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la récupération du solde."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="operations", description="Afficher les dernières opérations d'un compte")
    @app_commands.describe(
        compte_id="ID du compte",
        limite="Nombre d'opérations à afficher (max 10)"
    )
    async def operations(
        self,
        interaction: discord.Interaction,
        compte_id: int,
        limite: Optional[int] = 10
    ):
        """Commande pour afficher les opérations"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Limiter à 10 opérations maximum
            limite = min(limite, 10)
            
            # Récupérer les opérations
            ops_data = await self.api_client.get_account_operations(token, compte_id, limit=limite)
            
            if not ops_data:
                embed = create_error_embed(
                    "Erreur",
                    "Compte non trouvé ou vous n'avez pas accès à ce compte."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            operations = ops_data.get('operations', [])
            
            # Récupérer le numéro de compte
            account = await self.api_client.get_account_details(token, compte_id)
            compte_numero = account.get('numero_compte', str(compte_id)) if account else str(compte_id)
            
            embed = create_operations_embed(
                operations,
                compte_numero,
                page=1,
                total=ops_data.get('pagination', {}).get('total')
            )
            
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la récupération des opérations: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la récupération des opérations."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="stats", description="Afficher vos statistiques bancaires")
    async def stats(self, interaction: discord.Interaction):
        """Commande pour afficher les statistiques"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Récupérer les statistiques
            stats = await self.api_client.get_user_stats(token)
            profile = await self.api_client.get_user_profile(token)
            
            if not stats or not profile:
                embed = create_error_embed(
                    "Erreur",
                    "Impossible de récupérer les statistiques."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            username = f"{profile['prenom']} {profile['nom']}"
            embed = create_stats_embed(stats, username)
            
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la récupération des statistiques: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la récupération des statistiques."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)


async def setup(bot: commands.Bot):
    """Fonction pour charger le cog"""
    await bot.add_cog(AccountsCog(bot))
