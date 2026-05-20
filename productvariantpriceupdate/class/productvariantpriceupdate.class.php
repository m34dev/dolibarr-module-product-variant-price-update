<?php
/* Copyright (C) 2026		William Mead			<william@m34d.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        htdocs/productvariantpriceupdate/class/productvariantpriceupdate.class.php
 * \ingroup     productvariantpriceupdate
 * \brief       Business logic for ProductVariantPriceUpdate module
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * Class for ProductVariantPriceUpdate
 */
class ProductVariantPriceUpdate
{
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * Handles each row of the variant price import profile, bypassing the standard
	 * import_insert() because llx_product_attribute_combination has no import_key column.
	 *
	 * @param	array		$parameters		Hook metadatas: 'datatoimport', 'arrayrecord', 'array_match_file_to_database'
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
}
