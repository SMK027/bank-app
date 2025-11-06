"""
Validateurs pour les entrées utilisateur
"""

from typing import Optional, Tuple


def validate_amount(amount: float) -> Tuple[bool, Optional[str]]:
    """Valide un montant"""
    if amount <= 0:
        return False, "Le montant doit être supérieur à 0"
    
    if amount > 1000000:
        return False, "Le montant ne peut pas dépasser 1 000 000 €"
    
    # Vérifier qu'il n'y a pas plus de 2 décimales
    if round(amount, 2) != amount:
        return False, "Le montant ne peut avoir que 2 décimales maximum"
    
    return True, None


def validate_operation_type(operation_type: str) -> Tuple[bool, Optional[str]]:
    """Valide un type d'opération"""
    valid_types = ['debit', 'credit', 'virement', 'prelevement', 'depot', 'retrait']
    
    if operation_type.lower() not in valid_types:
        return False, f"Type d'opération invalide. Types valides: {', '.join(valid_types)}"
    
    return True, None


def validate_string_length(text: str, min_length: int = 0, max_length: int = 255, field_name: str = "Champ") -> Tuple[bool, Optional[str]]:
    """Valide la longueur d'une chaîne"""
    if len(text) < min_length:
        return False, f"{field_name} doit contenir au moins {min_length} caractères"
    
    if len(text) > max_length:
        return False, f"{field_name} ne peut pas dépasser {max_length} caractères"
    
    return True, None


def validate_account_id(account_id: int) -> Tuple[bool, Optional[str]]:
    """Valide un ID de compte"""
    if account_id <= 0:
        return False, "L'ID du compte doit être un nombre positif"
    
    return True, None


def sanitize_input(text: str) -> str:
    """Nettoie une entrée utilisateur"""
    # Supprimer les espaces en début et fin
    text = text.strip()
    
    # Remplacer les caractères spéciaux problématiques
    replacements = {
        '\n': ' ',
        '\r': ' ',
        '\t': ' ',
    }
    
    for old, new in replacements.items():
        text = text.replace(old, new)
    
    # Réduire les espaces multiples
    while '  ' in text:
        text = text.replace('  ', ' ')
    
    return text


def format_operation_type_display(operation_type: str) -> str:
    """Formate un type d'opération pour l'affichage"""
    display_names = {
        'debit': 'Débit',
        'credit': 'Crédit',
        'virement': 'Virement',
        'prelevement': 'Prélèvement',
        'depot': 'Dépôt',
        'retrait': 'Retrait'
    }
    
    return display_names.get(operation_type.lower(), operation_type.capitalize())
