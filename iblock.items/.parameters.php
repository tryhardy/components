<?php
use Bitrix\Main\Localization\Loc;
use Uplab\Core\Components\TemplateBlock;


if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
/**
 * @var array $arCurrentValues
 * @var array $arComponentParameters
 * @var array $templateProperties
 */

Loc::loadMessages(__FILE__);

$templateProperties = $templateProperties ?? [];

$arComponentParameters = [
	'GROUPS'     => [],
	'PARAMETERS' => [
		'IBLOCK_ID'    => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_IBLOCK_ID'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'COUNT_LIMIT'  => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_COUNT_LIMIT'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '200',
		],
		'SORT_BY1'     => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_SORT_BY1'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'SORT_ORDER1'  => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_SORT_ORDER1'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'SORT_BY2'     => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_SORT_BY2'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'SORT_ORDER2'  => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_SORT_ORDER2'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'FILTER'       => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_FILTER'),
			'TYPE'    => 'STRING',
			'DEFAULT' => '',
		],
		'RETURN_ITEMS' => [
			'PARENT'  => 'DATA_SOURCE',
			'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_RETURN_ITEMS'),
			'TYPE'    => 'CHECKBOX',
			'DEFAULT' => 'N',
		],
        'ADDITIONAL_SELECT' => [
            'PARENT' => 'DATA_SOURCE',
            'NAME' => Loc::getMessage('ITEMS_LIST_PARAMETERS_ADDITIONAL_SELECT'),
            'TYPE' => 'STRING',
            'MULTIPLE' => 'Y',
        ],
		'CACHE_TIME'   => ['DEFAULT' => 36000000],
	],
];

CBitrixComponent::includeComponentClass("uplab.core:template.block");

$arComponentParameters['PARAMETERS']['INCLUDE_SUBSECTIONS'] = [
	'PARENT'  => 'DATA_SOURCE',
	'NAME'    => Loc::getMessage('ITEMS_LIST_PARAMETERS_INCLUDE_SUBSECTIONS'),
	'TYPE'    => 'CHECKBOX',
	'DEFAULT' => 'Y',
];
