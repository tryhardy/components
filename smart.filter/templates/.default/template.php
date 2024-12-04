<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/**
 * @var SimpleCatalogSmartFilterComponent $component
 * @var CBitrixComponentTemplate $this
 * @var array $arResult
 */

$bxajaxid = CAjax::GetComponentID($component->__name, $this->__name, '');

echo '<pre>';
print_r($arResult);
echo '</pre>';
