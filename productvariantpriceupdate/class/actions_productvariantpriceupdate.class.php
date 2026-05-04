<?php
/* Copyright (C) 2026		William Mead		<william@m34d.com>
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
 * \file    htdocs/productvariantpriceupdate/class/actions_productvariantpriceupdate.class.php
 * \ingroup productvariantpriceupdate
 * \brief   Hooks
 */

require_once "productvariantpriceupdate.class.php";

/**
 * Class ActionsProductVariantPriceUpdate
 */
class ActionsProductVariantPriceUpdate
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Registers the "Variants" column into $arrayfields (passed by reference) so it appears
	 * in the column selector. Must fire before multiSelectArrayWithCheckbox() is called.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...). Includes arrayfields by reference.
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager): int
	{
		global $arrayfields, $db, $langs, $user;

		if (in_array('productservicelist', $hookmanager->contextarray)) {
			if (!isModEnabled('variants')) {
				return 0;
			}

			$langs->load("productvariantpriceupdate@productvariantpriceupdate");

			$arrayfields['pvpu.variants'] = array(
				'label'    => $langs->trans("Variants"),
				'checked'  => 1,
				'enabled'  => 1,
				'position' => 15,
			);
			$arrayfields['pvpu.variation_price'] = array(
				'label'    => $langs->trans("VariationPrice"),
				'checked'  => 0,
				'enabled'  => 1,
				'position' => 16,
			);
			$arrayfields['pvpu.variation_weight'] = array(
				'label'    => $langs->trans("VariationWeight"),
				'checked'  => 0,
				'enabled'  => 1,
				'position' => 17,
			);

			return 0;
		}

		if (in_array('productpricecard', $hookmanager->contextarray) && $action === 'pvpu_update_variant_price') {
			if (!($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
				return 0;
			}
			if (!isModEnabled('variants') || !$object->isVariant()) {
				return 0;
			}

			$langs->load("productvariantpriceupdate@productvariantpriceupdate");

			require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
			require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

			$comb = new ProductCombination($db);
			if ($comb->fetchByFkProductChild($object->id) > 0) {
				$parent = new Product($db);
				if ($parent->fetch($comb->fk_product_parent) > 0) {
					$result = $comb->updateProperties($parent, $user);
					if ($result < 0) {
						dol_syslog(__METHOD__.' updateProperties failed for product id='.$object->id.': '.$comb->error, LOG_ERR);
						setEventMessages($comb->error, $comb->errors, 'errors');
						return -1;
					}
					setEventMessages($langs->trans("VariantPriceUpdatedFromParent"), null, 'mesgs');
				}
			}

			return 0;
		}

		return 0;
	}

	/**
	 * Overloading the formObjectOptions function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager): int
	{
		global $langs;
		$langs->load("productvariantpriceupdate@productvariantpriceupdate");
		return 0;
	}

	/**
	 * Adds an empty filter cell for the variant status column.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager): int
	{
		if (!in_array('productservicelist', $hookmanager->contextarray)) {
			return 0;
		}

		$out = '';
		if (!empty($parameters['arrayfields']['pvpu.variants']['checked'])) {
			$out .= '<td class="liste_titre"></td>';
		}
		if (!empty($parameters['arrayfields']['pvpu.variation_price']['checked'])) {
			$out .= '<td class="liste_titre"></td>';
		}
		if (!empty($parameters['arrayfields']['pvpu.variation_weight']['checked'])) {
			$out .= '<td class="liste_titre"></td>';
		}

		if ($out) {
			$this->resprints = $out;
		}

		return 0;
	}

	/**
	 * Adds the "Variants" column header to the product list.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager): int
	{
		global $langs;

		if (!in_array('productservicelist', $hookmanager->contextarray)) {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		$out = '';
		if (!empty($parameters['arrayfields']['pvpu.variants']['checked'])) {
			$out .= '<th class="center">'.$langs->trans("Variants").'</th>';
			$parameters['totalarray']['nbfield']++;
		}
		if (!empty($parameters['arrayfields']['pvpu.variation_price']['checked'])) {
			$out .= '<th class="center">'.$langs->trans("VariationPrice").'</th>';
			$parameters['totalarray']['nbfield']++;
		}
		if (!empty($parameters['arrayfields']['pvpu.variation_weight']['checked'])) {
			$out .= '<th class="center">'.$langs->trans("VariationWeight").'</th>';
			$parameters['totalarray']['nbfield']++;
		}

		if ($out) {
			$this->resprints = $out;
		}

		return 0;
	}

	/**
	 * Renders the variant status cell for each product row:
	 * - Parent with variants: shows the variant count
	 * - Variant (child): shows a "Variant" badge
	 * - Plain product: empty cell
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...). Includes 'obj', 'i', 'totalarray'.
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager): int
	{
		global $db, $langs;

		if (!in_array('productservicelist', $hookmanager->contextarray)) {
			return 0;
		}

		$showVariants       = !empty($parameters['arrayfields']['pvpu.variants']['checked']);
		$showVariationPrice  = !empty($parameters['arrayfields']['pvpu.variation_price']['checked']);
		$showVariationWeight = !empty($parameters['arrayfields']['pvpu.variation_weight']['checked']);

		if (!$showVariants && !$showVariationPrice && !$showVariationWeight) {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		$isVariant  = (bool) $object->isVariant();
		$nbVariants = $isVariant ? 0 : (int) $object->hasVariants();

		// Fetch combination data once if any variation column is needed
		$comb = null;
		if ($isVariant && ($showVariationPrice || $showVariationWeight)) {
			require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
			$comb = new ProductCombination($db);
			if ($comb->fetchByFkProductChild($object->id) <= 0) {
				$comb = null;
			}
		}

		$out = '';

		if ($showVariants) {
			$cell = '';
			if ($isVariant) {
				$cell = '<span class="opacitymedium">'.$langs->trans("Variant").'</span>';
			} elseif ($nbVariants > 0) {
				$cell = $langs->trans("NbVariants", $nbVariants);
			}
			$out .= '<td class="center">'.$cell.'</td>';
			if ($parameters['i'] == 0) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		if ($showVariationPrice) {
			$cell = '';
			if ($comb !== null) {
				$sign = ((float) $comb->variation_price) >= 0 ? '+' : '';
				if ($comb->variation_price_percentage) {
					$cell = $sign.$comb->variation_price.'%';
				} else {
					$cell = $sign.price($comb->variation_price);
				}
			}
			$out .= '<td class="center">'.$cell.'</td>';
			if ($parameters['i'] == 0) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		if ($showVariationWeight) {
			$cell = '';
			if ($comb !== null && (float) $comb->variation_weight != 0) {
				$sign = ((float) $comb->variation_weight) >= 0 ? '+' : '';
				$cell = $sign.$comb->variation_weight;
			}
			$out .= '<td class="center">'.$cell.'</td>';
			if ($parameters['i'] == 0) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		$this->resprints = $out;

		return 0;
	}

	/**
	 * Adds an "Update price from parent" button on the product price card for variant products.
	 * Prints directly since price.php does not print $hookmanager->resPrint after this hook.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The product object
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager): int
	{
		global $langs, $user;

		if (!in_array('productpricecard', $hookmanager->contextarray)) {
			return 0;
		}
		if (!isModEnabled('variants') || !$object->isVariant()) {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		if ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="pvpu_update_variant_price">';
			print '<input type="hidden" name="id" value="'.$object->id.'">';
			print '<div class="inline-block divButAction">';
			print '<input type="submit" class="butAction" value="'.$langs->trans("UpdateVariantPriceFromParent").'">';
			print '</div>';
			print '</form>';
		} else {
			print '<div class="inline-block divButAction">';
			print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans("NotEnoughPermissions")).'">'.$langs->trans("UpdateVariantPriceFromParent").'</span>';
			print '</div>';
		}

		return 0;
	}

	/**
	 * Overloading the loadStaticObject function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function loadStaticObject($parameters, &$object, &$action, $hookmanager): int
	{
		return 0;
	}

	/**
	 * Replaces the standard import row handler for the variant price import profile.
	 * Updates variation fields directly on llx_product_attribute_combination (bypassing
	 * the framework's import_key column which does not exist on that table), then calls
	 * updateProperties() to propagate the change to the child product's selling price.
	 * Returns 1 to tell the framework to skip its own import_insert() for this row.
	 *
	 * @param	array		$parameters		Hook metadatas: 'datatoimport', 'arrayrecord', 'array_match_file_to_database', 'nbok'
	 * @param	mixed		&$object		Not used
	 * @param	string		&$action		Not used
	 * @param	HookManager	$hookmanager	Hook manager
	 * @return	int							< 0 on error, 0 on success (standard code runs), 1 to replace standard code
	 */
	public function ImportInsert($parameters, &$object, &$action, $hookmanager): int
	{
		global $db, $langs, $user;

		if ($parameters['datatoimport'] !== 'productvariantpriceupdate_variantprices') {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		// Build a dbfield => value map from the column mapping and raw CSV record.
		// array_match_file_to_database keys are 1-based; arrayrecord is 0-based.
		$colValues = array();
		foreach ($parameters['array_match_file_to_database'] as $colkey => $dbfield) {
			$colValues[$dbfield] = $parameters['arrayrecord'][(int) $colkey - 1]['val'] ?? null;
		}

		$childRef           = $colValues['pac.fk_product_child'] ?? null;
		$variationPrice     = $colValues['pac.variation_price'] ?? null;
		$variationIsPercent = $colValues['pac.variation_price_percentage'] ?? null;
		$variationWeight    = $colValues['pac.variation_weight'] ?? null;

		if (empty($childRef)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("ChildProductRef"));
			return -1;
		}
		if ($variationPrice === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPrice"));
			return -1;
		}
		if ($variationIsPercent === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPriceIsPercent"));
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$child = new Product($db);
		if ($child->fetch(0, $childRef) <= 0) {
			dol_syslog(__METHOD__.' Could not fetch child product ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorProductNotFound", $childRef);
			return -1;
		}

		$comb = new ProductCombination($db);
		if ($comb->fetchByFkProductChild($child->id) <= 0) {
			dol_syslog(__METHOD__.' No combination found for child product id='.$child->id.' ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorNotACombinationProduct", $childRef);
			return -1;
		}

		$comb->variation_price            = (float) $variationPrice;
		$comb->variation_price_percentage = (int) $variationIsPercent;
		if ($variationWeight !== null) {
			$comb->variation_weight = (float) $variationWeight;
		}

		$sql  = 'UPDATE '.MAIN_DB_PREFIX.'product_attribute_combination SET';
		$sql .= ' variation_price = '.((float) $comb->variation_price);
		$sql .= ', variation_price_percentage = '.((int) $comb->variation_price_percentage);
		if ($variationWeight !== null) {
			$sql .= ', variation_weight = '.((float) $comb->variation_weight);
		}
		$sql .= ' WHERE fk_product_child = '.((int) $child->id);
		$sql .= ' AND entity IN ('.getEntity('product').')';

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' SQL error updating combination for child ref='.$childRef.': '.$db->lasterror(), LOG_ERR);
			$this->error = $db->lasterror();
			return -1;
		}

		$parent = new Product($db);
		if ($parent->fetch($comb->fk_product_parent) <= 0) {
			dol_syslog(__METHOD__.' Could not fetch parent product id='.$comb->fk_product_parent.' for child ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorProductParentNotFound", $childRef);
			return -1;
		}

		$result = $comb->updateProperties($parent, $user);
		if ($result < 0) {
			dol_syslog(__METHOD__.' updateProperties failed for child ref='.$childRef.': '.$comb->error, LOG_ERR);
			$this->error = $comb->error;
			return -1;
		}

		// Increment the driver's update counter directly on the object — this is what
		// the simulation summary displays. Using $parameters['obj'] (an object handle)
		// is reliable across by-value array copies, unlike scalar references.
		$parameters['obj']->nbupdate++;

		return 1;
	}

	/**
	 * Adds the "Update variant prices" option to the mass action dropdown on the product list.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	mixed			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager): int
	{
		global $langs, $user;

		if (!in_array('productservicelist', $hookmanager->contextarray)) {
			return 0;
		}
		if (!isModEnabled('variants')) {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		$disabled = !$user->hasRight('produit', 'creer') ? ' disabled="disabled"' : '';
		$this->resprints = '<option value="updatevariantprices"'.$disabled.'>'.$langs->trans("UpdateVariantPrices").'</option>';

		return 0;
	}

	/**
	 * Handles the "updatevariantprices" mass action: calls ProductCombination::updateProperties()
	 * for every combination of each selected parent product.
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...). Includes 'toselect' and 'massaction'.
	 * @param	object			&$object		The object to process
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager): int
	{
		global $db, $langs, $user;

		if (!in_array('productservicelist', $hookmanager->contextarray)) {
			return 0;
		}
		if ($parameters['massaction'] !== 'updatevariantprices') {
			return 0;
		}
		if (!$user->hasRight('produit', 'creer')) {
			return 0;
		}
		if (!isModEnabled('variants')) {
			return 0;
		}

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$error = 0;
		$nbUpdated = 0;

		foreach ($parameters['toselect'] as $productId) {
			$parent = new Product($db);
			if ($parent->fetch((int) $productId) <= 0) {
				dol_syslog(__METHOD__.' Failed to fetch product id='.$productId, LOG_ERR);
				continue;
			}

			if (!$parent->hasVariants()) {
				if ($parent->isVariant()) {
					setEventMessages($langs->trans("ProductIsVariantNoChildren", $parent->ref), null, 'warnings');
				} else {
					setEventMessages($langs->trans("ProductHasNoVariants", $parent->ref), null, 'warnings');
				}
				continue;
			}

			$comb = new ProductCombination($db);
			$combinations = $comb->fetchAllByFkProductParent($parent->id);

			foreach ($combinations as $currcomb) {
				$result = $currcomb->updateProperties($parent, $user);
				if ($result < 0) {
					dol_syslog(__METHOD__.' updateProperties failed for combination id='.$currcomb->id.' (parent id='.$parent->id.'): '.$currcomb->error, LOG_ERR);
					$this->errors[] = $currcomb->error;
					$this->errors = array_merge($this->errors, $currcomb->errors);
					$error++;
				} else {
					$nbUpdated++;
				}
			}
		}

		if ($error) {
			setEventMessages('', $this->errors, 'errors');
			return -1;
		}

		setEventMessages($langs->trans("VariantPricesUpdated", $nbUpdated), null, 'mesgs');
		return 1;
	}
}
