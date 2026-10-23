<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Controller\Admin;

use LinguaFlowAI\Queue\JobRepository;
use LinguaFlowAI\Settings;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Target of the "Translate with AI" bulk action of the product grid.
 */
class BulkTranslateController extends FrameworkBundleAdminController
{
    public function bulkTranslateAction(Request $request): RedirectResponse
    {
        $back = $request->headers->get('referer') ?: $this->generateUrl('admin_products_index');
        // all() without arguments works on both Symfony 4.4 (PS 8) and 6.4 (PS 9).
        $posted = $request->request->all();
        $productIds = array_map('intval', (array) ($posted['product_bulk'] ?? []));
        $langIds = Settings::targetLanguageIds();
        $context = \Context::getContext();

        if ($langIds === []) {
            $this->addFlash('error', 'LinguaFlow AI: enable at least one target language in the module settings first.');

            return new RedirectResponse($back);
        }
        if ($productIds === []) {
            $this->addFlash('error', 'LinguaFlow AI: no product selected.');

            return new RedirectResponse($back);
        }

        /** @var \LinguaFlowAI $module */
        $module = \Module::getInstanceByName('linguaflowai');
        $idJob = (new JobRepository())->createJob(
            JobRepository::TYPE_BULK,
            sprintf('Product list selection (%d products)', count($productIds)),
            $productIds,
            $langIds,
            $module->defaultJobOptions(),
            (int) $context->employee->id,
            (int) $context->shop->id
        );

        if ($idJob) {
            $this->addFlash('success', sprintf(
                'LinguaFlow AI: job #%d queued (%d products x %d languages). Products are updated as each translation arrives; you will get a notification when it is done.',
                $idJob,
                count($productIds),
                count($langIds)
            ));
        } else {
            $this->addFlash('warning', 'LinguaFlow AI: the selected products are already queued for these languages.');
        }

        return new RedirectResponse($back);
    }
}
