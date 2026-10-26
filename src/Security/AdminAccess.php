<?php
namespace LinguaFlowAI\Security;

/** Explicit authorization for custom actions, before legacy postProcess dispatch. */
final class AdminAccess
{
    public static function allows($controller, string $permission, bool $products = false, bool $configure = false): bool
    {
        $employee = \Context::getContext()->employee;
        if (!$employee || !$employee->isLoggedBack() || !$controller->checkToken() || !$controller->access('view') || !$controller->access($permission)) {
            return false;
        }
        if ($products && !\Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE', (int) $employee->id_profile)) {
            return false;
        }
        if ($configure) {
            if (!\Access::isGranted('ROLE_MOD_MODULE_LINGUAFLOWAI_UPDATE', (int) $employee->id_profile)) {
                return false;
            }
            // The provider, glossary and memory are global, so a shop-limited employee cannot manage them.
            foreach (\Shop::getShops(false, null, true) as $idShop) {
                if (!$employee->hasAuthOnShop((int) $idShop)) { return false; }
            }
        }
        return true;
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
}
