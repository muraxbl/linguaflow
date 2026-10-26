<?php
namespace LinguaFlowAI\Security;

/** Indexation and hooks must see the same shop as the translated row. */
final class ShopContext
{
    public static function run(int $idShop, callable $action)
    {
        $context = \Context::getContext();
        $previousShop = $context->shop;
        $previousMode = \Shop::getContext();
        $previousId = $previousMode === \Shop::CONTEXT_SHOP ? \Shop::getContextShopID() : \Shop::getContextShopGroupID();
        $shop = new \Shop($idShop);
        if ($idShop < 1 || !\Validate::isLoadedObject($shop) || !$shop->active) {
            throw new \DomainException('The target shop is unavailable.');
        }
        try {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
            $context->shop = $shop;
            return $action();
        } finally {
            \Shop::setContext($previousMode, $previousId);
            $context->shop = $previousShop;
        }
    }
}
