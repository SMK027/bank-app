"""
Commandes d'authentification et de liaison Discord
"""

import discord
from discord import app_commands
from discord.ext import commands
import logging

from utils.api_client import BankAPIClient
from utils.embeds import create_success_embed, create_error_embed, create_info_embed
from config import API_BASE_URL, MSG_NOT_LINKED

logger = logging.getLogger(__name__)


class AuthCog(commands.Cog):
    """Gestion de l'authentification et de la liaison Discord"""
    
    def __init__(self, bot: commands.Bot):
        self.bot = bot
        self.api_client = BankAPIClient()
    
    async def cog_load(self):
        """Appelé lors du chargement du cog"""
        logger.info("AuthCog chargé")
    
    async def cog_unload(self):
        """Appelé lors du déchargement du cog"""
        if self.api_client.session:
            await self.api_client.session.close()
        logger.info("AuthCog déchargé")
    
    @app_commands.command(name="link", description="Lier votre compte bancaire à Discord")
    async def link(self, interaction: discord.Interaction):
        """Commande pour lier son compte bancaire"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            # Vérifier si l'utilisateur est déjà lié
            token = await self.api_client.get_user_token(str(interaction.user.id))
            
            if token:
                embed = create_info_embed(
                    "Compte déjà lié",
                    "Votre compte Discord est déjà lié à un compte bancaire.\n"
                    "Utilisez `/status` pour voir les détails ou `/unlink` pour délier votre compte."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Créer le lien d'autorisation OAuth2
            oauth_url = f"{API_BASE_URL}/auth/discord/authorize"
            
            embed = create_info_embed(
                "Liaison de compte",
                "Pour lier votre compte bancaire à Discord, suivez ces étapes:\n\n"
                "1. Connectez-vous à votre compte bancaire sur le site web\n"
                "2. Allez dans votre profil\n"
                "3. Cliquez sur 'Lier mon compte Discord'\n"
                "4. Autorisez l'application Discord\n\n"
                "Une fois la liaison effectuée, vous pourrez utiliser toutes les commandes du bot."
            )
            
            embed.add_field(
                name="🔗 Lien direct",
                value=f"[Cliquez ici pour vous connecter]({API_BASE_URL.replace('/api', '')}/login.php)",
                inline=False
            )
            
            embed.set_footer(text="La liaison est sécurisée et peut être révoquée à tout moment")
            
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la liaison: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la tentative de liaison."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="unlink", description="Délier votre compte Discord du compte bancaire")
    async def unlink(self, interaction: discord.Interaction):
        """Commande pour délier son compte Discord"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            # Vérifier si l'utilisateur est lié
            token = await self.api_client.get_user_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed(
                    "Compte non lié",
                    MSG_NOT_LINKED
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Créer une vue de confirmation
            view = UnlinkConfirmView(self.api_client, token)
            
            embed = create_info_embed(
                "Confirmation requise",
                "⚠️ Êtes-vous sûr de vouloir délier votre compte Discord?\n\n"
                "Vous ne pourrez plus utiliser les commandes du bot jusqu'à ce que vous reliiez votre compte."
            )
            
            await interaction.followup.send(embed=embed, view=view, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la déliaison: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la tentative de déliaison."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="status", description="Vérifier le statut de votre liaison Discord")
    async def status(self, interaction: discord.Interaction):
        """Commande pour vérifier le statut de la liaison"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            # Vérifier si l'utilisateur est lié
            token = await self.api_client.get_user_token(str(interaction.user.id))
            
            if not token:
                embed = create_info_embed(
                    "Compte non lié",
                    MSG_NOT_LINKED + "\n\nUtilisez `/link` pour lier votre compte."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Récupérer les informations de liaison
            discord_info = await self.api_client.get_discord_link_status(token)
            profile = await self.api_client.get_user_profile(token)
            
            if not discord_info or not profile:
                embed = create_error_embed(
                    "Erreur",
                    "Impossible de récupérer les informations de liaison."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            embed = create_success_embed(
                "Compte lié",
                f"Votre compte Discord est lié au compte bancaire de **{profile['prenom']} {profile['nom']}**"
            )
            
            embed.add_field(
                name="👤 Utilisateur",
                value=profile['username'],
                inline=True
            )
            
            embed.add_field(
                name="📧 Email",
                value=profile['email'],
                inline=True
            )
            
            embed.add_field(
                name="🏦 Rôle",
                value=profile['role'].capitalize(),
                inline=True
            )
            
            if discord_info.get('linked'):
                discord_data = discord_info.get('discord', {})
                embed.add_field(
                    name="🔗 Lié depuis",
                    value=discord_data.get('linked_at', 'N/A'),
                    inline=True
                )
                
                if discord_data.get('last_used'):
                    embed.add_field(
                        name="🕒 Dernière utilisation",
                        value=discord_data.get('last_used'),
                        inline=True
                    )
            
            embed.set_footer(text="Utilisez /unlink pour délier votre compte")
            
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la vérification du statut: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la vérification du statut."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)


class UnlinkConfirmView(discord.ui.View):
    """Vue de confirmation pour la déliaison"""
    
    def __init__(self, api_client: BankAPIClient, token: str):
        super().__init__(timeout=60)
        self.api_client = api_client
        self.token = token
    
    @discord.ui.button(label="Confirmer", style=discord.ButtonStyle.danger)
    async def confirm(self, interaction: discord.Interaction, button: discord.ui.Button):
        """Bouton de confirmation"""
        await interaction.response.defer()
        
        success = await self.api_client.unlink_discord(self.token)
        
        if success:
            embed = create_success_embed(
                "Compte délié",
                "Votre compte Discord a été délié avec succès.\n"
                "Utilisez `/link` pour le lier à nouveau."
            )
        else:
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la déliaison."
            )
        
        # Désactiver les boutons
        for item in self.children:
            item.disabled = True
        
        await interaction.edit_original_response(embed=embed, view=self)
    
    @discord.ui.button(label="Annuler", style=discord.ButtonStyle.secondary)
    async def cancel(self, interaction: discord.Interaction, button: discord.ui.Button):
        """Bouton d'annulation"""
        embed = create_info_embed(
            "Annulé",
            "La déliaison a été annulée."
        )
        
        # Désactiver les boutons
        for item in self.children:
            item.disabled = True
        
        await interaction.response.edit_message(embed=embed, view=self)


async def setup(bot: commands.Bot):
    """Fonction pour charger le cog"""
    await bot.add_cog(AuthCog(bot))
