<?php
/* Copyright (C) 2026       William Mead    <william@m34d.com>
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

// use Luracast\Restler\RestException;
//
// dol_include_once('/productvariantpriceupdate/class/productvariantpriceupdate.class.php');
//
//
//
// /**
//  * \file    htdocs/productvariantpriceupdate/class/api_productvariantpriceupdate.class.php
//  * \ingroup productvariantpriceupdate
//  * \brief   API for productvariantpriceupdate module
//  */
//
// /**
//  * API class for productvariantpriceupdate
//  * @access protected
//  * @class  DolibarrApiAccess {@requires user,external}
//  */
// class ProductVariantPriceUpdateApi extends DolibarrApi
// {
//
// 	/**
// 	 * Constructor
// 	 *
// 	 * @url     GET /
// 	 */
// 	public function __construct()
// 	{
// 		global $db;
// 		$this->db = $db;
// 	}
//
//
// 	/* BEGIN MODULEBUILDER API MYOBJECT */
//
// 	/**
// 	 * Get Product Variant Price Update
// 	 *
// 	 * Return Product Variant Price Update
// 	 *
// 	 * @param	string		$ref	Ref of Product Variant Price Update
// 	 * @return	Int					Product Variant Price Updatev
// 	 *
// 	 * @url	GET productvariantpriceupdate/{ref}
// 	 *
// 	 * @throws RestException 403 Not allowed
// 	 * @throws RestException 404 Not found
// 	 * @throws RestException 500 Internal server error
// 	 */
// 	public function get($ref)
// 	{
// 		if (!DolibarrApiAccess::$user->hasRight('produit', 'lire')) {
// 			throw new RestException(403);
// 		}
// 		if (!DolibarrApiAccess::$user->hasRight('stock', 'lire')) {
// 			throw new RestException(403);
// 		}
// 		$product = new Product($this->db);
// 		$result = $product->fetch(0, $ref);
// 		if ($result == -1) {
// 			throw new RestException(404, 'Product not found');
// 		}
// 		$product_id = $product->id;
// 		$result = 1;
// 		if ($result == -1) {
// 			throw new RestException(404, 'Product not found');
// 		}
// 		if ($result == -2) {
// 			throw new RestException(500, 'Product is a service');
// 		}
// 		if ($result == -3) {
// 			throw new RestException(500, 'Product has no subproducts');
// 		}
//
// 		return $result;
// 	}
// }
