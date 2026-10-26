<?php
namespace LinguaFlowAI\Security;

final class ShopScope
{
    public const CONTEXT = 'context';
    public const ALL = 'all';

    /** All active shops this employee may operate on, independent of the selector. */
    public static function authorizedIds(): array
    {
        $employee = \Context::getContext()->employee;
        if (!$employee || !$employee->isLoggedBack()) {
            return [];
        }
        return array_values(array_filter(array_map('intval', \Shop::getShops(true, null, true)), function ($id) use ($employee) {
            return $id > 0 && $employee->hasAuthOnShop($id);
        }));
    }

    public static function targets(string $scope): array
    {
        $allowed = self::authorizedIds();
        if ($scope === self::ALL && $allowed) {
            return $allowed;
        }
        $id = (int) \Context::getContext()->shop->id;
        if ($scope !== self::CONTEXT || \Shop::getContext() !== \Shop::CONTEXT_SHOP || !in_array($id, $allowed, true)) {
            throw new \DomainException('Select an authorized shop in the shop selector, or choose all authorized shops.');
        }
        return [$id];
    }
}
