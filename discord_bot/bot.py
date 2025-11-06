"""
Bot Discord pour Bank App
Point d'entrée principal
"""

import discord
from discord.ext import commands
import logging
import asyncio
from pathlib import Path

from config import (
    DISCORD_BOT_TOKEN, BOT_PREFIX, BOT_DESCRIPTION,
    validate_config
)

# Configuration du logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('bot.log'),
        logging.StreamHandler()
    ]
)

logger = logging.getLogger(__name__)


class BankBot(commands.Bot):
    """Classe principale du bot bancaire"""
    
    def __init__(self):
        # Intents nécessaires
        intents = discord.Intents.default()
        intents.message_content = True
        intents.members = True
        
        super().__init__(
            command_prefix=BOT_PREFIX,
            description=BOT_DESCRIPTION,
            intents=intents
        )
    
    async def setup_hook(self):
        """Appelé lors de la configuration du bot"""
        logger.info("Configuration du bot...")
        
        # Charger les cogs
        cogs_dir = Path(__file__).parent / 'cogs'
        
        for cog_file in cogs_dir.glob('*.py'):
            if cog_file.name.startswith('_'):
                continue
            
            cog_name = f'cogs.{cog_file.stem}'
            
            try:
                await self.load_extension(cog_name)
                logger.info(f"Cog chargé: {cog_name}")
            except Exception as e:
                logger.error(f"Erreur lors du chargement de {cog_name}: {e}")
        
        # Synchroniser les commandes slash
        try:
            synced = await self.tree.sync()
            logger.info(f"{len(synced)} commandes slash synchronisées")
        except Exception as e:
            logger.error(f"Erreur lors de la synchronisation des commandes: {e}")
    
    async def on_ready(self):
        """Appelé lorsque le bot est prêt"""
        logger.info(f"Bot connecté en tant que {self.user} (ID: {self.user.id})")
        logger.info(f"Connecté à {len(self.guilds)} serveur(s)")
        
        # Définir le statut du bot
        await self.change_presence(
            activity=discord.Activity(
                type=discord.ActivityType.watching,
                name="vos comptes bancaires 🏦"
            )
        )
    
    async def on_command_error(self, ctx: commands.Context, error: commands.CommandError):
        """Gestion des erreurs de commandes"""
        if isinstance(error, commands.CommandNotFound):
            return
        
        if isinstance(error, commands.MissingRequiredArgument):
            await ctx.send(f"❌ Argument manquant: {error.param.name}")
            return
        
        if isinstance(error, commands.BadArgument):
            await ctx.send(f"❌ Argument invalide: {error}")
            return
        
        logger.error(f"Erreur de commande: {error}", exc_info=error)
        await ctx.send("❌ Une erreur s'est produite lors de l'exécution de la commande.")
    
    async def on_app_command_error(
        self,
        interaction: discord.Interaction,
        error: discord.app_commands.AppCommandError
    ):
        """Gestion des erreurs de commandes slash"""
        if isinstance(error, discord.app_commands.CommandOnCooldown):
            await interaction.response.send_message(
                f"⏱️ Cette commande est en cooldown. Réessayez dans {error.retry_after:.1f}s",
                ephemeral=True
            )
            return
        
        if isinstance(error, discord.app_commands.MissingPermissions):
            await interaction.response.send_message(
                "❌ Vous n'avez pas les permissions nécessaires pour utiliser cette commande.",
                ephemeral=True
            )
            return
        
        logger.error(f"Erreur de commande slash: {error}", exc_info=error)
        
        if not interaction.response.is_done():
            await interaction.response.send_message(
                "❌ Une erreur s'est produite lors de l'exécution de la commande.",
                ephemeral=True
            )
        else:
            await interaction.followup.send(
                "❌ Une erreur s'est produite lors de l'exécution de la commande.",
                ephemeral=True
            )
    
    async def on_guild_join(self, guild: discord.Guild):
        """Appelé lorsque le bot rejoint un serveur"""
        logger.info(f"Bot ajouté au serveur: {guild.name} (ID: {guild.id})")
    
    async def on_guild_remove(self, guild: discord.Guild):
        """Appelé lorsque le bot quitte un serveur"""
        logger.info(f"Bot retiré du serveur: {guild.name} (ID: {guild.id})")


async def main():
    """Fonction principale"""
    try:
        # Valider la configuration
        validate_config()
        logger.info("Configuration validée")
        
        # Créer et démarrer le bot
        bot = BankBot()
        
        async with bot:
            await bot.start(DISCORD_BOT_TOKEN)
            
    except ValueError as e:
        logger.error(f"Erreur de configuration: {e}")
        return
    except discord.LoginFailure:
        logger.error("Erreur d'authentification: token Discord invalide")
        return
    except Exception as e:
        logger.error(f"Erreur fatale: {e}", exc_info=True)
        return


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Bot arrêté par l'utilisateur")
    except Exception as e:
        logger.error(f"Erreur lors du démarrage: {e}", exc_info=True)
