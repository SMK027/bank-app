"""
Commandes pour la gestion des opérations bancaires
"""

import discord
from discord import app_commands
from discord.ext import commands
import logging
from typing import Optional

from utils.api_client import BankAPIClient
from utils.embeds import (
    create_error_embed, create_operation_confirmation_embed,
    create_info_embed
)
from utils.validators import (
    validate_amount, validate_operation_type,
    validate_string_length, sanitize_input,
    format_operation_type_display
)
from config import MSG_NOT_LINKED

logger = logging.getLogger(__name__)


class OperationsCog(commands.Cog):
    """Gestion des opérations bancaires"""
    
    def __init__(self, bot: commands.Bot):
        self.bot = bot
        self.api_client = BankAPIClient()
    
    async def cog_load(self):
        """Appelé lors du chargement du cog"""
        logger.info("OperationsCog chargé")
    
    async def cog_unload(self):
        """Appelé lors du déchargement du cog"""
        if self.api_client.session:
            await self.api_client.session.close()
        logger.info("OperationsCog déchargé")
    
    async def get_token(self, user_id: str) -> Optional[str]:
        """Récupère le token de l'utilisateur"""
        return await self.api_client.get_user_token(user_id)
    
    @app_commands.command(name="operation", description="Enregistrer une nouvelle opération bancaire")
    @app_commands.describe(
        compte_id="ID du compte",
        type_operation="Type d'opération (credit, debit, virement, depot, retrait, prelevement)",
        montant="Montant de l'opération",
        destinataire="Destinataire de l'opération (optionnel)",
        nature="Nature de l'opération (optionnel)",
        description="Description de l'opération (optionnel)"
    )
    @app_commands.choices(type_operation=[
        app_commands.Choice(name="Crédit", value="credit"),
        app_commands.Choice(name="Débit", value="debit"),
        app_commands.Choice(name="Virement", value="virement"),
        app_commands.Choice(name="Dépôt", value="depot"),
        app_commands.Choice(name="Retrait", value="retrait"),
        app_commands.Choice(name="Prélèvement", value="prelevement")
    ])
    async def operation(
        self,
        interaction: discord.Interaction,
        compte_id: int,
        type_operation: str,
        montant: float,
        destinataire: Optional[str] = None,
        nature: Optional[str] = None,
        description: Optional[str] = None
    ):
        """Commande pour créer une opération"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Valider le montant
            valid, error = validate_amount(montant)
            if not valid:
                embed = create_error_embed("Montant invalide", error)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Valider le type d'opération
            valid, error = validate_operation_type(type_operation)
            if not valid:
                embed = create_error_embed("Type d'opération invalide", error)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Nettoyer et valider les champs optionnels
            if destinataire:
                destinataire = sanitize_input(destinataire)
                valid, error = validate_string_length(destinataire, max_length=255, field_name="Destinataire")
                if not valid:
                    embed = create_error_embed("Destinataire invalide", error)
                    await interaction.followup.send(embed=embed, ephemeral=True)
                    return
            
            if nature:
                nature = sanitize_input(nature)
                valid, error = validate_string_length(nature, max_length=100, field_name="Nature")
                if not valid:
                    embed = create_error_embed("Nature invalide", error)
                    await interaction.followup.send(embed=embed, ephemeral=True)
                    return
            
            if description:
                description = sanitize_input(description)
                valid, error = validate_string_length(description, max_length=1000, field_name="Description")
                if not valid:
                    embed = create_error_embed("Description invalide", error)
                    await interaction.followup.send(embed=embed, ephemeral=True)
                    return
            
            # Créer une vue de confirmation
            view = OperationConfirmView(
                self.api_client,
                token,
                compte_id,
                type_operation,
                montant,
                destinataire,
                nature,
                description
            )
            
            # Créer l'embed de confirmation
            embed = create_info_embed(
                "Confirmation d'opération",
                f"⚠️ Vous êtes sur le point d'enregistrer l'opération suivante:\n\n"
                f"**Type:** {format_operation_type_display(type_operation)}\n"
                f"**Montant:** {montant:.2f} €\n"
                f"**Compte ID:** {compte_id}"
            )
            
            if destinataire:
                embed.add_field(name="Destinataire", value=destinataire, inline=True)
            if nature:
                embed.add_field(name="Nature", value=nature, inline=True)
            if description:
                embed.add_field(name="Description", value=description, inline=False)
            
            embed.set_footer(text="Confirmez pour enregistrer l'opération")
            
            await interaction.followup.send(embed=embed, view=view, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la création de l'opération: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la création de l'opération."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)
    
    @app_commands.command(name="search", description="Rechercher des opérations")
    @app_commands.describe(
        compte_id="ID du compte (optionnel)",
        type_operation="Type d'opération (optionnel)",
        nature="Nature de l'opération (optionnel)",
        destinataire="Destinataire (optionnel)",
        limite="Nombre de résultats (max 20)"
    )
    @app_commands.choices(type_operation=[
        app_commands.Choice(name="Crédit", value="credit"),
        app_commands.Choice(name="Débit", value="debit"),
        app_commands.Choice(name="Virement", value="virement"),
        app_commands.Choice(name="Dépôt", value="depot"),
        app_commands.Choice(name="Retrait", value="retrait"),
        app_commands.Choice(name="Prélèvement", value="prelevement")
    ])
    async def search(
        self,
        interaction: discord.Interaction,
        compte_id: Optional[int] = None,
        type_operation: Optional[str] = None,
        nature: Optional[str] = None,
        destinataire: Optional[str] = None,
        limite: Optional[int] = 10
    ):
        """Commande pour rechercher des opérations"""
        await interaction.response.defer(ephemeral=True)
        
        try:
            token = await self.get_token(str(interaction.user.id))
            
            if not token:
                embed = create_error_embed("Compte non lié", MSG_NOT_LINKED)
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Limiter à 20 résultats maximum
            limite = min(limite, 20)
            
            # Rechercher les opérations
            operations = await self.api_client.search_operations(
                token,
                compte_id=compte_id,
                type_operation=type_operation,
                nature=nature,
                destinataire=destinataire,
                limit=limite
            )
            
            if operations is None:
                embed = create_error_embed(
                    "Erreur",
                    "Une erreur s'est produite lors de la recherche."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            if not operations:
                embed = create_info_embed(
                    "Aucun résultat",
                    "Aucune opération ne correspond à vos critères de recherche."
                )
                await interaction.followup.send(embed=embed, ephemeral=True)
                return
            
            # Créer l'embed avec les résultats
            from utils.embeds import create_operations_embed
            
            compte_numero = "Tous les comptes"
            if compte_id and operations:
                # Essayer de récupérer le numéro de compte
                account = await self.api_client.get_account_details(token, compte_id)
                if account:
                    compte_numero = account.get('numero_compte', str(compte_id))
            
            embed = create_operations_embed(operations, compte_numero)
            embed.title = f"🔍 Résultats de recherche"
            
            # Ajouter les critères de recherche
            criteres = []
            if type_operation:
                criteres.append(f"Type: {format_operation_type_display(type_operation)}")
            if nature:
                criteres.append(f"Nature: {nature}")
            if destinataire:
                criteres.append(f"Destinataire: {destinataire}")
            
            if criteres:
                embed.description = "**Critères:** " + " • ".join(criteres)
            
            await interaction.followup.send(embed=embed, ephemeral=True)
            
        except Exception as e:
            logger.error(f"Erreur lors de la recherche: {e}")
            embed = create_error_embed(
                "Erreur",
                "Une erreur s'est produite lors de la recherche."
            )
            await interaction.followup.send(embed=embed, ephemeral=True)


class OperationConfirmView(discord.ui.View):
    """Vue de confirmation pour une opération"""
    
    def __init__(
        self,
        api_client: BankAPIClient,
        token: str,
        compte_id: int,
        type_operation: str,
        montant: float,
        destinataire: Optional[str],
        nature: Optional[str],
        description: Optional[str]
    ):
        super().__init__(timeout=60)
        self.api_client = api_client
        self.token = token
        self.compte_id = compte_id
        self.type_operation = type_operation
        self.montant = montant
        self.destinataire = destinataire
        self.nature = nature
        self.description = description
    
    @discord.ui.button(label="Confirmer", style=discord.ButtonStyle.success)
    async def confirm(self, interaction: discord.Interaction, button: discord.ui.Button):
        """Bouton de confirmation"""
        await interaction.response.defer()
        
        # Créer l'opération
        response = await self.api_client.create_operation(
            self.token,
            self.compte_id,
            self.type_operation,
            self.montant,
            self.destinataire,
            self.nature,
            self.description
        )
        
        if response.get('success'):
            data = response.get('data', {})
            operation = data.get('operation', {})
            ancien_solde = data.get('ancien_solde', 0)
            nouveau_solde = data.get('nouveau_solde', 0)
            
            embed = create_operation_confirmation_embed(operation, ancien_solde, nouveau_solde)
        else:
            embed = create_error_embed(
                "Erreur",
                response.get('error', 'Une erreur s\'est produite lors de l\'enregistrement de l\'opération')
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
            "L'opération a été annulée."
        )
        
        # Désactiver les boutons
        for item in self.children:
            item.disabled = True
        
        await interaction.response.edit_message(embed=embed, view=self)


async def setup(bot: commands.Bot):
    """Fonction pour charger le cog"""
    await bot.add_cog(OperationsCog(bot))
