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
if ($batchSize === 0) {
	$batchSize = 50;
}
$batchOffset = max(0, (int) GETPOST('batch_offset', 'int'));
$batchRan = false;
$batchNbUpdated = 0;
$batchNbErrors = 0;
$batchNbParentsProcessed = 0;
$batchNbParentsTotal = 0;
$batchDone = false;

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

if ($action == 'batch_update_variant_prices' && $user->admin && isModEnabled('variants')) {
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$resql = $db->query('SELECT COUNT(DISTINCT fk_product_parent) as nb FROM '.MAIN_DB_PREFIX.'product_attribute_combination WHERE entity IN ('.getEntity('product').')');
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$batchNbParentsTotal = (int) $obj->nb;
		}
	}

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
				if ($currcomb->updateProperties($parent, $user) < 0) {
					dol_syslog('productvariantpriceupdate setup batch_update: updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
					$batchNbErrors++;
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

if ($action == 'update_all_variant_prices' && $user->admin && isModEnabled('variants')) {
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
				if ($currcomb->updateProperties($parent, $user) < 0) {
					dol_syslog('productvariantpriceupdate setup update_all: updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
					$nbErrors++;
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

/*
 * View
 */

$form = new Form($db);

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
