<?php

namespace App\Enums;

enum CaseEnum: string
{
	case SNAKE = 'snake';
	case CAMEL = 'camel';
	case PASCAL = 'pascal';
	case KEBAB = 'kebab';
}
