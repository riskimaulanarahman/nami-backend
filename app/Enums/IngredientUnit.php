<?php

namespace App\Enums;

enum IngredientUnit: string
{
    case Pcs = 'pcs';
    case Kg = 'kg';
    case Gram = 'gram';
    case Liter = 'liter';
    case Ml = 'ml';
    case Sendok = 'sendok';
    case Bungkus = 'bungkus';
    case Porsi = 'porsi';
    case Botol = 'botol';
}
