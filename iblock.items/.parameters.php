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

$arComponentParameters = [];