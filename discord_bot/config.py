"""
Configuration du bot Discord pour Bank App
"""

import os
from dotenv import load_dotenv

# Charger les variables d'environnement
load_dotenv()

# Configuration Discord
DISCORD_BOT_TOKEN = os.getenv('DISCORD_BOT_TOKEN')
DISCORD_CLIENT_ID = os.getenv('DISCORD_CLIENT_ID')
DISCORD_CLIENT_SECRET = os.getenv('DISCORD_CLIENT_SECRET')

# Configuration API
API_BASE_URL = os.getenv('API_BASE_URL', 'https://votre-domaine.com/api')
API_BOT_TOKEN = os.getenv('API_BOT_TOKEN')  # Token secret pour authentifier le bot auprès de l'API

# Configuration du bot
BOT_PREFIX = '/'  # Utiliser les slash commands
BOT_DESCRIPTION = 'Bot bancaire pour gérer vos comptes via Discord'

# Couleurs des embeds
COLOR_SUCCESS = 0x00FF00  # Vert
COLOR_ERROR = 0xFF0000    # Rouge
COLOR_INFO = 0x0099FF     # Bleu
COLOR_WARNING = 0xFFAA00  # Orange

# Emojis
EMOJI_MONEY = '💰'
EMOJI_BANK = '🏦'
EMOJI_CARD = '💳'
EMOJI_CHECK = '✅'
EMOJI_CROSS = '❌'
EMOJI_WARNING = '⚠️'
EMOJI_INFO = 'ℹ️'
EMOJI_CHART = '📊'
EMOJI_CALENDAR = '📅'
EMOJI_ARROW_UP = '📈'
EMOJI_ARROW_DOWN = '📉'

# Limites
MAX_OPERATIONS_DISPLAY = 10
CACHE_TIMEOUT = 300  # 5 minutes

# Messages
MSG_NOT_LINKED = "Vous n'avez pas encore lié votre compte bancaire. Utilisez `/link` pour commencer."
MSG_ERROR_API = "Une erreur s'est produite lors de la communication avec l'API bancaire."
MSG_ERROR_PERMISSION = "Vous n'avez pas la permission d'effectuer cette action."

# Validation
def validate_config():
    """Valide que toutes les variables de configuration nécessaires sont définies"""
    required_vars = {
        'DISCORD_BOT_TOKEN': DISCORD_BOT_TOKEN,
        'API_BASE_URL': API_BASE_URL,
        'API_BOT_TOKEN': API_BOT_TOKEN
    }
    
    missing = [name for name, value in required_vars.items() if not value]
    
    if missing:
        raise ValueError(f"Variables d'environnement manquantes: {', '.join(missing)}")
    
    return True
