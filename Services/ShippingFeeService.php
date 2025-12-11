<?php

namespace App\Services;

class ShippingFeeService
{
    /**
     * Calculate shipping fee based on location and total weight
     * 
     * Rules:
     * - Within Cebu: FREE
     * - Outside Cebu: 
     *   - Minimum: 100 pesos
     *   - Maximum: 1000 pesos
     *   - 5 pesos per kg
     *   - Below 20kg: 100 pesos
     *   - Above 20kg: 100 + (weight - 20) * 5, capped at 1000
     * 
     * @param string|null $locationType 'within_cebu' or 'outside_cebu'
     * @param float $totalWeight Total weight in kg
     * @return float Shipping fee
     */
    public static function calculate($locationType, $totalWeight = 0)
    {
        // Within Cebu area: FREE shipping
        if ($locationType === 'within_cebu') {
            return 0;
        }

        // Outside Cebu area: Calculate based on weight
        if ($locationType === 'outside_cebu') {
            $minFee = 100;
            $maxFee = 1000;
            $ratePerKg = 5;

            // If weight is below 20kg, charge minimum fee
            if ($totalWeight < 20) {
                return $minFee;
            }

            // Calculate: 100 + (weight - 20) * 5
            $calculatedFee = $minFee + (($totalWeight - 20) * $ratePerKg);

            // Cap at maximum fee
            return min($calculatedFee, $maxFee);
        }

        // Default: If location type is not specified, assume outside Cebu
        return self::calculate('outside_cebu', $totalWeight);
    }

    /**
     * Calculate total weight from cart items
     * 
     * @param array $items Cart items with product information
     * @return float Total weight in kg
     */
    public static function calculateTotalWeight($items)
    {
        $totalWeight = 0;

        foreach ($items as $item) {
            $product = $item['product'] ?? null;
            $quantity = $item['quantity'] ?? 1;

            if ($product) {
                // Get weight from product
                $weight = 0;
                if (isset($product['weight'])) {
                    // Weight might be a string like "0.5 kg" or just a number
                    $weightStr = $product['weight'];
                    if (is_string($weightStr)) {
                        // Extract numeric value
                        preg_match('/(\d+\.?\d*)/', $weightStr, $matches);
                        $weight = isset($matches[1]) ? (float)$matches[1] : 0;
                    } else {
                        $weight = (float)$weightStr;
                    }
                }

                $totalWeight += $weight * $quantity;
            } else {
                // Default weight if not specified (assume 0.5kg per item)
                $totalWeight += 0.5 * $quantity;
            }
        }

        return $totalWeight;
    }
}









































