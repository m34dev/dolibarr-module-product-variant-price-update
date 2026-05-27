<?php
/* Copyright (C) 2026       William Mead    <william@m34d.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    productvariantpriceupdate/admin/setup.php
 * \ingroup productvariantpriceupdate
 * \brief   ProductVariantPriceUpdate setup page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/productvariantpriceupdate.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "productvariantpriceupdate@productvariantpriceupdate"));

$hookmanager->initHooks(array('productvariantpriceupdatesetup', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Batch state
$batchSize = max(1, (int) GETPOST('batch_size', 'int'));
$batchOffset = max(0, (int) GETPOST('batch_offset', 'int'));
$batchRan = false;
$batchNbUpdated = 0;
$batchNbErrors = 0;
$batchNbParentsProcessed = 0;
$batchNbParentsTotal = 0;
$batchDone = false;
$batchErrorProducts = [];
$errorProducts = [];
$continueOffset = null;
$continueBatchSize = null;
$progressStart = 0;
$progressEnd = 0;
$progressTotal = 0;

// Stats
$nbParentProducts = 0;
$nbChildProducts = 0;

if (isModEnabled('variants')) {
	$resql = $db->query('SELECT COUNT(DISTINCT fk_product_parent) as nb FROM '.MAIN_DB_PREFIX.'product_attribute_combination WHERE entity IN ('.getEntity('product').')');
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$nbParentProducts = (int) $obj->nb;
		}
	}

	$resql = $db->query('SELECT COUNT(DISTINCT fk_product_child) as nb FROM '.MAIN_DB_PREFIX.'product_attribute_combination WHERE entity IN ('.getEntity('product').')');
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$nbChildProducts = (int) $obj->nb;
		}
	}
}

/*
 * Actions
 */

if ($action == 'batch_update_variant_prices' && isModEnabled('variants')) {
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$batchNbParentsTotal = $nbParentProducts;

	$resql = $db->query('SELECT DISTINCT fk_product_parent FROM '.MAIN_DB_PREFIX.'product_attribute_combination WHERE entity IN ('.getEntity('product').') ORDER BY fk_product_parent LIMIT '.(int) $batchSize.' OFFSET '.(int) $batchOffset);

	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$batchNbParentsProcessed++;
			$parent = new Product($db);
			if ($parent->fetch((int) $obj->fk_product_parent) <= 0) {
				dol_syslog('productvariantpriceupdate setup batch_update: Failed to fetch product id='.$obj->fk_product_parent, LOG_ERR);
				continue;
			}

			$comb = new ProductCombination($db);
			$combinations = $comb->fetchAllByFkProductParent($parent->id);

			foreach ($combinations as $currcomb) {
				$typeCheck = pvpu_check_combination_types($currcomb);
				if ($typeCheck !== true) {
					dol_syslog('productvariantpriceupdate setup batch_update: type check failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$typeCheck, LOG_ERR);
					$batchNbErrors++;
					$batchErrorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
					continue;
				}
				try {
					$updateResult = $currcomb->updateProperties($parent, $user);
				} catch (TypeError $e) {
					dol_syslog('productvariantpriceupdate setup batch_update: TypeError in updateProperties for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$e->getMessage(), LOG_ERR);
					$batchNbErrors++;
					$batchErrorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
					continue;
				}
				if ($updateResult < 0) {
					dol_syslog('productvariantpriceupdate setup batch_update: updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
					$batchNbErrors++;
					$batchErrorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
				} else {
					$batchNbUpdated++;
				}
			}
		}
	} else {
		dol_syslog('productvariantpriceupdate setup batch_update: SQL query failed: '.$db->lasterror(), LOG_ERR);
		$batchNbErrors++;
	}

	$batchRan = true;
	$batchDone = ($batchNbParentsProcessed < $batchSize);

	if ($batchNbErrors) {
		setEventMessages($langs->trans("AllVariantPricesUpdateErrors", $batchNbErrors), null, 'errors');
	}
	if ($batchNbUpdated) {
		setEventMessages($langs->trans("AllVariantPricesUpdated", $batchNbUpdated), null, 'mesgs');
	}
}

if ($action == 'update_all_variant_prices' && isModEnabled('variants')) {
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$nbUpdated = 0;
	$nbErrors = 0;

	$resql = $db->query('SELECT DISTINCT fk_product_parent FROM '.MAIN_DB_PREFIX.'product_attribute_combination WHERE entity IN ('.getEntity('product').')');

	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$parent = new Product($db);
			if ($parent->fetch((int) $obj->fk_product_parent) <= 0) {
				dol_syslog('productvariantpriceupdate setup update_all: Failed to fetch product id='.$obj->fk_product_parent, LOG_ERR);
				continue;
			}

			$comb = new ProductCombination($db);
			$combinations = $comb->fetchAllByFkProductParent($parent->id);

			foreach ($combinations as $currcomb) {
				$typeCheck = pvpu_check_combination_types($currcomb);
				if ($typeCheck !== true) {
					dol_syslog('productvariantpriceupdate setup update_all: type check failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$typeCheck, LOG_ERR);
					$nbErrors++;
					$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
					continue;
				}
				try {
					$updateResult = $currcomb->updateProperties($parent, $user);
				} catch (TypeError $e) {
					dol_syslog('productvariantpriceupdate setup update_all: TypeError in updateProperties for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$e->getMessage(), LOG_ERR);
					$nbErrors++;
					$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
					continue;
				}
				if ($updateResult < 0) {
					dol_syslog('productvariantpriceupdate setup update_all: updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
					$nbErrors++;
					$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
				} else {
					$nbUpdated++;
				}
			}
		}
	} else {
		dol_syslog('productvariantpriceupdate setup update_all: SQL query failed: '.$db->lasterror(), LOG_ERR);
		$nbErrors++;
	}

	if ($nbErrors) {
		setEventMessages($langs->trans("AllVariantPricesUpdateErrors", $nbErrors), null, 'errors');
	}
	if ($nbUpdated) {
		setEventMessages($langs->trans("AllVariantPricesUpdated", $nbUpdated), null, 'mesgs');
	}
}

if ($action == 'reprocess_error_products' && isModEnabled('variants')) {
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$reprocessIds = array_filter(array_map('intval', (array) GETPOST('reprocess_ids', 'array')));
	$nbReprocessUpdated = 0;
	$nbReprocessErrors = 0;

	if (GETPOSTISSET('next_batch_offset')) {
		$continueOffset = max(0, (int) GETPOST('next_batch_offset', 'int'));
		$continueBatchSize = max(1, (int) GETPOST('batch_size', 'int'));
		$progressStart = max(1, (int) GETPOST('batch_progress_start', 'int'));
		$progressEnd = max(0, (int) GETPOST('batch_progress_end', 'int'));
		$progressTotal = max(0, (int) GETPOST('batch_progress_total', 'int'));
	}

	foreach ($reprocessIds as $productId) {
		$parent = new Product($db);
		if ($parent->fetch($productId) <= 0) {
			dol_syslog('productvariantpriceupdate setup reprocess: Failed to fetch product id='.$productId, LOG_ERR);
			continue;
		}

		$comb = new ProductCombination($db);
		$combinations = $comb->fetchAllByFkProductParent($parent->id);

		foreach ($combinations as $currcomb) {
			$typeCheck = pvpu_check_combination_types($currcomb);
			if ($typeCheck !== true) {
				dol_syslog('productvariantpriceupdate setup reprocess: type check failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$typeCheck, LOG_ERR);
				$nbReprocessErrors++;
				$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
				continue;
			}
			try {
				$updateResult = $currcomb->updateProperties($parent, $user);
			} catch (TypeError $e) {
				dol_syslog('productvariantpriceupdate setup reprocess: TypeError in updateProperties for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$e->getMessage(), LOG_ERR);
				$nbReprocessErrors++;
				$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
				continue;
			}
			if ($updateResult < 0) {
				dol_syslog('productvariantpriceupdate setup reprocess: updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
				$nbReprocessErrors++;
				$errorProducts[$parent->id] = array('id' => $parent->id, 'ref' => $parent->ref, 'label' => $parent->label);
			} else {
				$nbReprocessUpdated++;
			}
		}
	}

	if ($nbReprocessErrors) {
		setEventMessages($langs->trans("AllVariantPricesUpdateErrors", $nbReprocessErrors), null, 'errors');
	}
	if ($nbReprocessUpdated) {
		setEventMessages($langs->trans("AllVariantPricesUpdated", $nbReprocessUpdated), null, 'mesgs');
	}
}

/*
 * Helpers
 */

/**
 * Checks that numeric fields on a combination object contain valid numeric values.
 * Returns true on success, or a human-readable error string describing the first
 * bad field found (so the caller can surface it to the user and skip the update).
 *
 * @param ProductCombination $comb
 * @return true|string
 */
function pvpu_check_combination_types(ProductCombination $comb)
{
	if (!is_numeric($comb->variation_price)) {
		return 'variation_price is not numeric: '.var_export($comb->variation_price, true);
	}
	if (!is_numeric($comb->variation_weight)) {
		return 'variation_weight is not numeric: '.var_export($comb->variation_weight, true);
	}
	if (!is_numeric($comb->variation_price_percentage)) {
		return 'variation_price_percentage is not numeric: '.var_export($comb->variation_price_percentage, true);
	}
	if (!empty($comb->variation_price_levels) && is_array($comb->variation_price_levels)) {
		foreach ($comb->variation_price_levels as $i => $level) {
			if (is_object($level)) {
				if (!is_numeric($level->variation_price)) {
					return 'variation_price_levels['.$i.'].variation_price is not numeric: '.var_export($level->variation_price, true);
				}
				if (!is_numeric($level->variation_price_percentage)) {
					return 'variation_price_levels['.$i.'].variation_price_percentage is not numeric: '.var_export($level->variation_price_percentage, true);
				}
			}
		}
	}
	return true;
}

/**
 * Casts numeric fields on a combination object to their expected types so that
 * ProductCombination::updateProperties() does not receive strings in arithmetic.
 * Call this only after pvpu_check_combination_types() has passed, or to fix known-safe data.
 *
 * @param ProductCombination $comb
 */
function pvpu_sanitize_combination_types(ProductCombination $comb): void
{
	$comb->variation_price            = (float) $comb->variation_price;
	$comb->variation_weight           = (float) $comb->variation_weight;
	$comb->variation_price_percentage = (int) $comb->variation_price_percentage;
	if (!empty($comb->variation_price_levels) && is_array($comb->variation_price_levels)) {
		foreach ($comb->variation_price_levels as $level) {
			if (is_object($level)) {
				$level->variation_price            = (float) $level->variation_price;
				$level->variation_price_percentage = (int) $level->variation_price_percentage;
			}
		}
	}
}

/**
 * Renders a reprocess form: product list with links and a submit button.
 * $extraHiddenFields carries batch-continuation state across the POST round-trip.
 *
 * @param array<int,array{id:int,ref:string,label:string}> $products
 * @param array<string,scalar> $extraHiddenFields
 */
function pvpu_print_error_product_form(array $products, array $extraHiddenFields = []): void
{
	global $langs;

	print '<p><strong>'.$langs->trans("ErrorProductsList").'</strong></p>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reprocess_error_products">';
	foreach ($extraHiddenFields as $name => $value) {
		print '<input type="hidden" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag((string) $value).'">';
	}
	print '<ul>';
	foreach ($products as $prod) {
		$url = DOL_URL_ROOT.'/product/card.php?id='.(int) $prod['id'];
		print '<li><a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($prod['ref']);
		if ($prod['label']) {
			print ' — '.dol_escape_htmltag($prod['label']);
		}
		print '</a></li>';
		print '<input type="hidden" name="reprocess_ids[]" value="'.(int) $prod['id'].'">';
	}
	print '</ul>';
	print '<input type="submit" class="button" value="'.$langs->trans("ReprocessErrorProducts").'">';
	print '</form>';
}

/*
 * View
 */

$title = "ProductVariantPriceUpdateSetup";

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-productvariantpriceupdate page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'object_productvariantpriceupdate@productvariantpriceupdate');

$head = productVariantPriceUpdateAdminPrepareHead();
print dol_get_fiche_head($head, 'Setup', $langs->trans($title), -1, "setup");

// Stats and bulk update
if (isModEnabled('variants')) {
	print load_fiche_titre($langs->trans("VariantPriceUpdateTool"), '', '');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("Statistic").'</td>';
	print '<td class="right">'.$langs->trans("Value").'</td>';
	print '</tr>';

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans("NbParentProducts").'</td>';
	print '<td class="right"><strong>'.$nbParentProducts.'</strong></td>';
	print '</tr>';

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans("NbChildProducts").'</td>';
	print '<td class="right"><strong>'.$nbChildProducts.'</strong></td>';
	print '</tr>';

	print '</table>';
	print '</div>';

	print '<br>';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_all_variant_prices">';
	print '<input type="submit" class="button" value="'.$langs->trans("UpdateAllVariantPrices").'">';
	print '</form>';

	if (!empty($errorProducts) && $continueOffset === null) {
		pvpu_print_error_product_form($errorProducts);
	}

	print '<br>';

	// Batch update section
	print load_fiche_titre($langs->trans("BatchVariantPriceUpdate"), '', '');

	if ($batchRan) {
		$nextOffset = $batchOffset + $batchNbParentsProcessed;
		$nbRemaining = max(0, $batchNbParentsTotal - $nextOffset);

		print '<p>';
		print $langs->trans("BatchProgress", $batchOffset + 1, min($nextOffset, $batchNbParentsTotal), $batchNbParentsTotal);
		if ($nbRemaining > 0) {
			print ' — '.$langs->trans("BatchRemaining", $nbRemaining);
		}
		print '</p>';

		if (!empty($batchErrorProducts)) {
			pvpu_print_error_product_form($batchErrorProducts, array(
				'next_batch_offset'    => $nextOffset,
				'batch_size'          => $batchSize,
				'batch_progress_start' => $batchOffset + 1,
				'batch_progress_end'   => min($nextOffset, $batchNbParentsTotal),
				'batch_progress_total' => $batchNbParentsTotal,
			));
		}

		if ($batchDone) {
			print '<p><strong>'.$langs->trans("BatchComplete").'</strong></p>';
		} else {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="batch_update_variant_prices">';
			print '<input type="hidden" name="batch_offset" value="'.$nextOffset.'">';
			print '<input type="hidden" name="batch_size" value="'.$batchSize.'">';
			print '<input type="submit" class="button" value="'.$langs->trans("BatchContinue").'">';
			print '</form>';
		}
	} elseif ($continueOffset !== null) {
		if ($progressTotal > 0) {
			$nbRemaining = max(0, $progressTotal - $progressEnd);
			print '<p>';
			print $langs->trans("BatchProgress", $progressStart, $progressEnd, $progressTotal);
			if ($nbRemaining > 0) {
				print ' — '.$langs->trans("BatchRemaining", $nbRemaining);
			}
			print '</p>';
		}

		if (!empty($errorProducts)) {
			pvpu_print_error_product_form($errorProducts, array(
				'next_batch_offset'    => $continueOffset,
				'batch_size'          => $continueBatchSize,
				'batch_progress_start' => $progressStart,
				'batch_progress_end'   => $progressEnd,
				'batch_progress_total' => $progressTotal,
			));
		}

		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="batch_update_variant_prices">';
		print '<input type="hidden" name="batch_offset" value="'.$continueOffset.'">';
		print '<input type="hidden" name="batch_size" value="'.$continueBatchSize.'">';
		print '<input type="submit" class="button" value="'.$langs->trans("BatchContinue").'">';
		print '</form>';
	} else {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="batch_update_variant_prices">';
		print '<input type="hidden" name="batch_offset" value="0">';
		print '<label for="batch_size">'.$langs->trans("BatchSize").'</label> ';
		print '<input type="number" id="batch_size" name="batch_size" value="50" min="1" style="width:80px;"> ';
		print '<input type="submit" class="button" value="'.$langs->trans("BatchRun").'">';
		print '</form>';
	}
} else {
	print '<div class="warning">'.$langs->trans("WarningModuleNotEnabled", 'Variants').'</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
