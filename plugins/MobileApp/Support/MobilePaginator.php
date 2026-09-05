<?php

namespace Plugin\MobileApp\Support;

final class MobilePaginator
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    public static function normalize(int $page, int $perPage): array
    {
        $page = max(1, $page);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $perPage = min(self::MAX_PER_PAGE, $perPage);
        return [$page, $perPage];
    }

    public static function payload(array $items, int $page, int $perPage, int $total): array
    {
        [$page, $perPage] = self::normalize($page, $perPage);
        return [
            'items' => array_values($items),
            'page' => $page,
            'perPage' => $perPage,
            'total' => max(0, $total),
        ];
    }
}
