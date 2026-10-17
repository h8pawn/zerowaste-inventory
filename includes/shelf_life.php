<?php
// Shelf-life estimation used to pre-fill expiration dates when stocking items.

function normalizeItemName(string $itemName): string {
    $normalized = strtolower(trim($itemName));
    return (string) preg_replace('/\s+/', ' ', $normalized);
}

function detectStorageType(string $storageLocation): string {
    $value = strtolower(trim($storageLocation));
    if ($value === '') {
        return 'ambient';
    }
    if (str_contains($value, 'freez') || str_contains($value, 'frozen') || str_contains($value, 'deep chill')) {
        return 'frozen';
    }
    foreach (['fridge', 'refriger', 'chiller', 'cold'] as $needle) {
        if (str_contains($value, $needle)) {
            return 'chilled';
        }
    }
    return 'ambient';
}

function getShelfLifeProfiles(): array {
    return [
        // Fruits
        'banana' => ['ambient' => 7, 'chilled' => 10, 'frozen' => 45],
        'apple' => ['ambient' => 30, 'chilled' => 60, 'frozen' => 120],
        'orange' => ['ambient' => 14, 'chilled' => 30, 'frozen' => 90],
        'mandarin' => ['ambient' => 10, 'chilled' => 21, 'frozen' => 60],
        'lemon' => ['ambient' => 14, 'chilled' => 28, 'frozen' => 90],
        'lime' => ['ambient' => 14, 'chilled' => 28, 'frozen' => 90],
        'grape' => ['ambient' => 4, 'chilled' => 10, 'frozen' => 60],
        'strawberry' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 60],
        'blueberry' => ['ambient' => 3, 'chilled' => 10, 'frozen' => 60],
        'raspberry' => ['ambient' => 2, 'chilled' => 4, 'frozen' => 45],
        'blackberry' => ['ambient' => 2, 'chilled' => 4, 'frozen' => 45],
        'watermelon' => ['ambient' => 7, 'chilled' => 10, 'frozen' => 45],
        'melon' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'cantaloupe' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'honeydew' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'pineapple' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 45],
        'mango' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'papaya' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 45],
        'pear' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 60],
        'peach' => ['ambient' => 3, 'chilled' => 7, 'frozen' => 45],
        'plum' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'kiwi' => ['ambient' => 7, 'chilled' => 21, 'frozen' => 60],
        'avocado' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 45],
        'cherry' => ['ambient' => 3, 'chilled' => 7, 'frozen' => 45],
        'pomegranate' => ['ambient' => 21, 'chilled' => 45, 'frozen' => 120],
        'guava' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 30],
        'jackfruit' => ['ambient' => 3, 'chilled' => 6, 'frozen' => 30],
        'durian' => ['ambient' => 2, 'chilled' => 4, 'frozen' => 30],
        'coconut' => ['ambient' => 30, 'chilled' => 45, 'frozen' => 120],
        // Vegetables
        'tomato' => ['ambient' => 5, 'chilled' => 7, 'frozen' => 45],
        'lettuce' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 30],
        'spinach' => ['ambient' => 2, 'chilled' => 4, 'frozen' => 30],
        'kale' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 45],
        'cabbage' => ['ambient' => 7, 'chilled' => 21, 'frozen' => 60],
        'broccoli' => ['ambient' => 2, 'chilled' => 7, 'frozen' => 45],
        'cauliflower' => ['ambient' => 3, 'chilled' => 7, 'frozen' => 45],
        'carrot' => ['ambient' => 7, 'chilled' => 21, 'frozen' => 60],
        'sweet potato' => ['ambient' => 30, 'chilled' => 45, 'frozen' => 120],
        'potato' => ['ambient' => 30, 'chilled' => 45, 'frozen' => 120],
        'onion' => ['ambient' => 30, 'chilled' => 45, 'frozen' => 90],
        'garlic' => ['ambient' => 45, 'chilled' => 60, 'frozen' => 120],
        'ginger' => ['ambient' => 14, 'chilled' => 30, 'frozen' => 90],
        'cucumber' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 30],
        'zucchini' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 30],
        'eggplant' => ['ambient' => 4, 'chilled' => 7, 'frozen' => 30],
        'bell pepper' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'pepper' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'chili' => ['ambient' => 5, 'chilled' => 10, 'frozen' => 45],
        'okra' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 30],
        'string bean' => ['ambient' => 3, 'chilled' => 6, 'frozen' => 30],
        'green bean' => ['ambient' => 3, 'chilled' => 6, 'frozen' => 30],
        'peas' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 45],
        'corn' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 45],
        'celery' => ['ambient' => 5, 'chilled' => 14, 'frozen' => 45],
        'asparagus' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 30],
        'mushroom' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 30],
        'radish' => ['ambient' => 5, 'chilled' => 14, 'frozen' => 45],
        'beet' => ['ambient' => 7, 'chilled' => 14, 'frozen' => 45],
        'turnip' => ['ambient' => 7, 'chilled' => 14, 'frozen' => 45],
        'squash' => ['ambient' => 10, 'chilled' => 14, 'frozen' => 60],
        'pumpkin' => ['ambient' => 30, 'chilled' => 45, 'frozen' => 120],
        'bok choy' => ['ambient' => 2, 'chilled' => 5, 'frozen' => 30],
        'chayote' => ['ambient' => 10, 'chilled' => 14, 'frozen' => 45],
        // Other common inventory
        'milk' => ['ambient' => 1, 'chilled' => 7, 'frozen' => 30],
        'bread' => ['ambient' => 5, 'chilled' => 7, 'frozen' => 30],
        'egg' => ['ambient' => 7, 'chilled' => 21, 'frozen' => 45],
        'chicken' => ['ambient' => 1, 'chilled' => 2, 'frozen' => 90],
    ];
}

function estimateBaselineShelfLifeDays(string $itemName, string $storageLocation = ''): array {
    $normalized = normalizeItemName($itemName);
    $storageType = detectStorageType($storageLocation);

    foreach (getShelfLifeProfiles() as $keyword => $profile) {
        if (str_contains($normalized, $keyword)) {
            return [
                'days' => (int) ($profile[$storageType] ?? $profile['ambient']),
                'source' => 'item_profile',
                'matched_keyword' => $keyword,
                'storage_type' => $storageType,
            ];
        }
    }

    return [
        'days' => $storageType === 'chilled' ? 7 : ($storageType === 'frozen' ? 45 : 5),
        'source' => 'fallback_profile',
        'matched_keyword' => null,
        'storage_type' => $storageType,
    ];
}

// Blends the keyword baseline with this store's own history for the same item name.
function estimateShelfLifeDays(string $itemName, string $storageLocation = '', ?int $manualShelfLife = null): array {
    if ($manualShelfLife !== null && $manualShelfLife > 0) {
        return [
            'days' => $manualShelfLife,
            'source' => 'manual_input',
            'sample_count' => 0,
            'matched_keyword' => null,
            'storage_type' => detectStorageType($storageLocation),
        ];
    }

    $baseline = estimateBaselineShelfLifeDays($itemName, $storageLocation);
    $result = $baseline + ['sample_count' => 0];
    $normalizedName = normalizeItemName($itemName);
    if ($normalizedName === '') {
        return $result;
    }

    $stats = db_one(
        'SELECT COUNT(*) AS sample_count, AVG(DATEDIFF(expiration_date, DATE(added_date))) AS avg_days
         FROM food_items
         WHERE LOWER(TRIM(name)) = ?
           AND DATEDIFF(expiration_date, DATE(added_date)) BETWEEN 1 AND 365',
        's',
        [$normalizedName]
    );

    $sampleCount = (int) ($stats['sample_count'] ?? 0);
    $avgDays = (float) ($stats['avg_days'] ?? 0);
    $result['sample_count'] = $sampleCount;

    if ($sampleCount >= 3 && $avgDays > 0) {
        $result['days'] = max(1, (int) round(($avgDays * 0.7) + ($baseline['days'] * 0.3)));
        $result['source'] = 'historical_blended';
    }

    return $result;
}

function dateAfterDays(int $days): string {
    return date('Y-m-d', strtotime('+' . max(1, $days) . ' days'));
}
