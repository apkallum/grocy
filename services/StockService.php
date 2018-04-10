<?php

class StockService
{
	const TRANSACTION_TYPE_PURCHASE = 'purchase';
	const TRANSACTION_TYPE_CONSUME = 'consume';
	const TRANSACTION_TYPE_INVENTORY_CORRECTION = 'inventory-correction';

	public static function GetCurrentStock()
	{
		$sql = 'SELECT * from stock_current';
		return DatabaseService::ExecuteDbQuery(DatabaseService::GetDbConnectionRaw(), $sql)->fetchAll(PDO::FETCH_OBJ);
	}

	public static function GetMissingProducts()
	{
		$sql = 'SELECT * from stock_missing_products';
		return DatabaseService::ExecuteDbQuery(DatabaseService::GetDbConnectionRaw(), $sql)->fetchAll(PDO::FETCH_OBJ);
	}

	public static function GetProductDetails(int $productId)
	{
		$db = DatabaseService::GetDbConnection();

		$product = $db->products($productId);
		$productStockAmount = $db->stock()->where('product_id', $productId)->sum('amount');
		$productLastPurchased = $db->stock_log()->where('product_id', $productId)->where('transaction_type', self::TRANSACTION_TYPE_PURCHASE)->max('purchased_date');
		$productLastUsed = $db->stock_log()->where('product_id', $productId)->where('transaction_type', self::TRANSACTION_TYPE_CONSUME)->max('used_date');
		$quPurchase = $db->quantity_units($product->qu_id_purchase);
		$quStock = $db->quantity_units($product->qu_id_stock);

		return array(
			'product' => $product,
			'last_purchased' => $productLastPurchased,
			'last_used' => $productLastUsed,
			'stock_amount' => $productStockAmount,
			'quantity_unit_purchase' => $quPurchase,
			'quantity_unit_stock' => $quStock
		);
	}

	public static function AddProduct(int $productId, int $amount, string $bestBeforeDate, $transactionType)
	{
		if ($transactionType === self::TRANSACTION_TYPE_CONSUME || $transactionType === self::TRANSACTION_TYPE_PURCHASE || $transactionType === self::TRANSACTION_TYPE_INVENTORY_CORRECTION)
		{
			$db = DatabaseService::GetDbConnection();
			$stockId = uniqid();

			$logRow = $db->stock_log()->createRow(array(
				'product_id' => $productId,
				'amount' => $amount,
				'best_before_date' => $bestBeforeDate,
				'purchased_date' => date('Y-m-d'),
				'stock_id' => $stockId,
				'transaction_type' => $transactionType
			));
			$logRow->save();

			$stockRow = $db->stock()->createRow(array(
				'product_id' => $productId,
				'amount' => $amount,
				'best_before_date' => $bestBeforeDate,
				'purchased_date' => date('Y-m-d'),
				'stock_id' => $stockId,
			));
			$stockRow->save();

			return true;
		}
		else
		{
			throw new Exception("Transaction type $transactionType is not valid (StockService.AddProduct)");
		}
	}

	public static function ConsumeProduct(int $productId, int $amount, bool $spoiled, $transactionType)
	{
		if ($transactionType === self::TRANSACTION_TYPE_CONSUME || $transactionType === self::TRANSACTION_TYPE_PURCHASE || $transactionType === self::TRANSACTION_TYPE_INVENTORY_CORRECTION)
		{
			$db = DatabaseService::GetDbConnection();

			$productStockAmount = $db->stock()->where('product_id', $productId)->sum('amount');
			$potentialStockEntries = $db->stock()->where('product_id', $productId)->orderBy('best_before_date', 'ASC')->orderBy('purchased_date', 'ASC')->fetchAll(); //First expiring first, then first in first out

			if ($amount > $productStockAmount)
			{
				return false;
			}

			foreach ($potentialStockEntries as $stockEntry)
			{
				if ($amount == 0)
				{
					break;
				}

				if ($amount >= $stockEntry->amount) //Take the whole stock entry
				{
					$logRow = $db->stock_log()->createRow(array(
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'used_date' => date('Y-m-d'),
						'spoiled' => $spoiled,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => $transactionType
					));
					$logRow->save();

					$amount -= $stockEntry->amount;
					$stockEntry->delete();
				}
				else //Stock entry amount is > than needed amount -> split the stock entry resp. update the amount
				{
					$logRow = $db->stock_log()->createRow(array(
						'product_id' => $stockEntry->product_id,
						'amount' => $amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'used_date' => date('Y-m-d'),
						'spoiled' => $spoiled,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => $transactionType
					));
					$logRow->save();

					$restStockAmount = $stockEntry->amount - $amount;
					$amount = 0;

					$stockEntry->update(array(
						'amount' => $restStockAmount
					));
				}
			}

			return true;
		}
		else
		{
			throw new Exception("Transaction type $transactionType is not valid (StockService.ConsumeProduct)");
		}
	}

	public static function InventoryProduct(int $productId, int $newAmount, string $bestBeforeDate)
	{
		$db = DatabaseService::GetDbConnection();
		$productStockAmount = $db->stock()->where('product_id', $productId)->sum('amount');

		if ($newAmount > $productStockAmount)
		{
			$amountToAdd = $newAmount - $productStockAmount;
			self::AddProduct($productId, $amountToAdd, $bestBeforeDate, self::TRANSACTION_TYPE_INVENTORY_CORRECTION);
		}
		else if ($newAmount < $productStockAmount)
		{
			$amountToRemove = $productStockAmount - $newAmount;
			self::ConsumeProduct($productId, $amountToRemove, false, self::TRANSACTION_TYPE_INVENTORY_CORRECTION);
		}

		return true;
	}

	public static function AddMissingProductsToShoppingList()
	{
		$db = DatabaseService::GetDbConnection();

		$missingProducts = self::GetMissingProducts();
		foreach ($missingProducts as $missingProduct)
		{
			$product = $db->products()->where('id', $missingProduct->id)->fetch();
			$amount = ceil($missingProduct->amount_missing / $product->qu_factor_purchase_to_stock);

			$alreadyExistingEntry = $db->shopping_list()->where('product_id', $missingProduct->id)->fetch();
			if ($alreadyExistingEntry) //Update
			{
				$alreadyExistingEntry->update(array(
					'amount_autoadded' => $amount
				));
			}
			else //Insert
			{
				$shoppinglistRow = $db->shopping_list()->createRow(array(
					'product_id' => $missingProduct->id,
					'amount_autoadded' => $amount
				));
				$shoppinglistRow->save();
			}
		}
	}
}