"""
Utilitaires pour créer des embeds Discord formatés
"""

import discord
from datetime import datetime
from typing import List, Dict, Optional
from config import (
    COLOR_SUCCESS, COLOR_ERROR, COLOR_INFO, COLOR_WARNING,
    EMOJI_MONEY, EMOJI_BANK, EMOJI_CARD, EMOJI_CHECK, EMOJI_CROSS,
    EMOJI_WARNING, EMOJI_INFO, EMOJI_CHART, EMOJI_CALENDAR,
    EMOJI_ARROW_UP, EMOJI_ARROW_DOWN
)


def format_currency(amount: float) -> str:
    """Formate un montant en devise"""
    return f"{amount:,.2f} €".replace(',', ' ')


def format_date(date_str: str) -> str:
    """Formate une date pour l'affichage"""
    try:
        dt = datetime.fromisoformat(date_str.replace('Z', '+00:00'))
        return dt.strftime('%d/%m/%Y %H:%M')
    except:
        return date_str


def get_operation_emoji(type_operation: str) -> str:
    """Retourne l'emoji correspondant au type d'opération"""
    emojis = {
        'credit': EMOJI_ARROW_UP,
        'debit': EMOJI_ARROW_DOWN,
        'depot': EMOJI_ARROW_UP,
        'retrait': EMOJI_ARROW_DOWN,
        'virement': '💸',
        'prelevement': '📤'
    }
    return emojis.get(type_operation, EMOJI_MONEY)


def create_success_embed(title: str, description: str) -> discord.Embed:
    """Crée un embed de succès"""
    embed = discord.Embed(
        title=f"{EMOJI_CHECK} {title}",
        description=description,
        color=COLOR_SUCCESS,
        timestamp=datetime.utcnow()
    )
    return embed


def create_error_embed(title: str, description: str) -> discord.Embed:
    """Crée un embed d'erreur"""
    embed = discord.Embed(
        title=f"{EMOJI_CROSS} {title}",
        description=description,
        color=COLOR_ERROR,
        timestamp=datetime.utcnow()
    )
    return embed


def create_info_embed(title: str, description: str) -> discord.Embed:
    """Crée un embed informatif"""
    embed = discord.Embed(
        title=f"{EMOJI_INFO} {title}",
        description=description,
        color=COLOR_INFO,
        timestamp=datetime.utcnow()
    )
    return embed


def create_warning_embed(title: str, description: str) -> discord.Embed:
    """Crée un embed d'avertissement"""
    embed = discord.Embed(
        title=f"{EMOJI_WARNING} {title}",
        description=description,
        color=COLOR_WARNING,
        timestamp=datetime.utcnow()
    )
    return embed


def create_accounts_embed(accounts: List[Dict], solde_total: float) -> discord.Embed:
    """Crée un embed pour afficher les comptes"""
    embed = discord.Embed(
        title=f"{EMOJI_BANK} Vos comptes bancaires",
        description=f"**Solde total:** {format_currency(solde_total)}",
        color=COLOR_INFO,
        timestamp=datetime.utcnow()
    )
    
    for account in accounts:
        solde = account['solde']
        emoji = EMOJI_ARROW_UP if solde >= 0 else EMOJI_ARROW_DOWN
        
        field_value = (
            f"**Type:** {account['type_compte'].capitalize()}\n"
            f"**Solde:** {emoji} {format_currency(solde)}\n"
            f"**Découvert autorisé:** {format_currency(account['negatif_autorise'])}\n"
            f"**Relation:** {account['relation'].capitalize()}"
        )
        
        embed.add_field(
            name=f"{EMOJI_CARD} Compte {account['numero_compte']}",
            value=field_value,
            inline=False
        )
    
    embed.set_footer(text=f"Nombre de comptes: {len(accounts)}")
    return embed


def create_balance_embed(account: Dict) -> discord.Embed:
    """Crée un embed pour afficher le solde d'un compte"""
    solde = account['solde']
    disponible = account['disponible']
    en_negatif = account['en_negatif']
    
    color = COLOR_ERROR if en_negatif else COLOR_SUCCESS
    emoji = EMOJI_ARROW_DOWN if en_negatif else EMOJI_ARROW_UP
    
    embed = discord.Embed(
        title=f"{EMOJI_BANK} Solde du compte {account['numero_compte']}",
        color=color,
        timestamp=datetime.utcnow()
    )
    
    embed.add_field(
        name=f"{emoji} Solde actuel",
        value=format_currency(solde),
        inline=True
    )
    
    embed.add_field(
        name=f"{EMOJI_MONEY} Disponible",
        value=format_currency(disponible),
        inline=True
    )
    
    embed.add_field(
        name="Type de compte",
        value=account['type_compte'].capitalize(),
        inline=True
    )
    
    if en_negatif:
        embed.add_field(
            name=f"{EMOJI_WARNING} Attention",
            value="Votre compte est en négatif !",
            inline=False
        )
    
    return embed


def create_operations_embed(
    operations: List[Dict],
    compte_numero: str,
    page: int = 1,
    total: Optional[int] = None
) -> discord.Embed:
    """Crée un embed pour afficher les opérations"""
    embed = discord.Embed(
        title=f"{EMOJI_CHART} Opérations du compte {compte_numero}",
        color=COLOR_INFO,
        timestamp=datetime.utcnow()
    )
    
    if not operations:
        embed.description = "Aucune opération trouvée."
        return embed
    
    for op in operations[:10]:  # Limiter à 10 opérations
        emoji = get_operation_emoji(op['type_operation'])
        montant = op['montant']
        
        # Formater le montant avec signe
        if op['type_operation'] in ['credit', 'depot']:
            montant_str = f"+{format_currency(montant)}"
        else:
            montant_str = f"-{format_currency(montant)}"
        
        field_name = f"{emoji} {op['type_operation'].capitalize()} - {montant_str}"
        
        field_value_parts = [
            f"**Date:** {format_date(op['date_operation'])}"
        ]
        
        if op.get('destinataire'):
            field_value_parts.append(f"**Destinataire:** {op['destinataire']}")
        
        if op.get('nature'):
            field_value_parts.append(f"**Nature:** {op['nature']}")
        
        if op.get('description'):
            desc = op['description'][:50] + '...' if len(op['description']) > 50 else op['description']
            field_value_parts.append(f"**Description:** {desc}")
        
        field_value_parts.append(f"**Solde après:** {format_currency(op['solde_apres'])}")
        
        embed.add_field(
            name=field_name,
            value='\n'.join(field_value_parts),
            inline=False
        )
    
    footer_text = f"Page {page}"
    if total:
        footer_text += f" • Total: {total} opérations"
    
    embed.set_footer(text=footer_text)
    return embed


def create_operation_confirmation_embed(operation: Dict, ancien_solde: float, nouveau_solde: float) -> discord.Embed:
    """Crée un embed de confirmation d'opération"""
    embed = discord.Embed(
        title=f"{EMOJI_CHECK} Opération enregistrée",
        color=COLOR_SUCCESS,
        timestamp=datetime.utcnow()
    )
    
    emoji = get_operation_emoji(operation['type_operation'])
    
    embed.add_field(
        name=f"{emoji} Type",
        value=operation['type_operation'].capitalize(),
        inline=True
    )
    
    embed.add_field(
        name=f"{EMOJI_MONEY} Montant",
        value=format_currency(operation['montant']),
        inline=True
    )
    
    embed.add_field(
        name=f"{EMOJI_CALENDAR} Date",
        value=format_date(operation['date_operation']),
        inline=True
    )
    
    if operation.get('destinataire'):
        embed.add_field(
            name="Destinataire",
            value=operation['destinataire'],
            inline=True
        )
    
    if operation.get('nature'):
        embed.add_field(
            name="Nature",
            value=operation['nature'],
            inline=True
        )
    
    embed.add_field(
        name="Ancien solde",
        value=format_currency(ancien_solde),
        inline=True
    )
    
    embed.add_field(
        name="Nouveau solde",
        value=format_currency(nouveau_solde),
        inline=True
    )
    
    if operation.get('description'):
        embed.add_field(
            name="Description",
            value=operation['description'],
            inline=False
        )
    
    return embed


def create_stats_embed(stats: Dict, username: str) -> discord.Embed:
    """Crée un embed pour afficher les statistiques"""
    embed = discord.Embed(
        title=f"{EMOJI_CHART} Statistiques de {username}",
        color=COLOR_INFO,
        timestamp=datetime.utcnow()
    )
    
    # Comptes
    comptes = stats.get('comptes', {})
    embed.add_field(
        name=f"{EMOJI_BANK} Comptes",
        value=(
            f"**Nombre:** {comptes.get('nombre', 0)}\n"
            f"**Solde total:** {format_currency(comptes.get('solde_total', 0))}"
        ),
        inline=False
    )
    
    # Opérations du mois
    ops_mois = stats.get('operations_mois', {})
    solde_mois = ops_mois.get('solde', 0)
    emoji_solde = EMOJI_ARROW_UP if solde_mois >= 0 else EMOJI_ARROW_DOWN
    
    embed.add_field(
        name=f"{EMOJI_CHART} Ce mois-ci",
        value=(
            f"**Opérations:** {ops_mois.get('nombre', 0)}\n"
            f"**Revenus:** {EMOJI_ARROW_UP} {format_currency(ops_mois.get('revenus', 0))}\n"
            f"**Dépenses:** {EMOJI_ARROW_DOWN} {format_currency(ops_mois.get('depenses', 0))}\n"
            f"**Solde:** {emoji_solde} {format_currency(solde_mois)}"
        ),
        inline=False
    )
    
    # Crédits
    credits = stats.get('credits', {})
    if credits.get('nombre', 0) > 0:
        embed.add_field(
            name=f"{EMOJI_CARD} Crédits",
            value=(
                f"**Nombre:** {credits.get('nombre', 0)}\n"
                f"**Restant à payer:** {format_currency(credits.get('montant_restant', 0))}"
            ),
            inline=False
        )
    
    return embed
