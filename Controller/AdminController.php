<?php
/**
 * @package AHS\AdvertsPluginBundle
 * @author Rafał Muszyński <rafal.muszynski@sourcefabric.org>
 * @copyright 2014 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace AHS\AdvertsPluginBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use AHS\AdvertsPluginBundle\TemplateList\AnnouncementCriteria;
use AHS\AdvertsPluginBundle\Entity\Announcement;
use AHS\AdvertsPluginBundle\Form\AnnouncementType;
use AHS\AdvertsPluginBundle\Form\SettingsType;

/**
 * Admin controller
 */
class AdminController extends Controller
{
    /**
     * @Route("/admin/announcements")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $em = $this->container->get('em');
        $adsService = $this->get('ahs_adverts_plugin.ads_service');
        $categories = $em->getRepository('AHS\AdvertsPluginBundle\Entity\Category')->findAll();

        return array(
            'categories' => $categories,
            'allAdsCount' => $adsService->countBy(),
            'activeAdsCount' => $adsService->countBy(array('is_active' => true)),
            'inactiveAdsCount' => $adsService->countBy(array('is_active' => false))
        );
    }

    /**
     * @Route("admin/announcements/load/", options={"expose"=true})
     */
    public function loadAdsAction(Request $request)
    {
        try {
        $em = $this->get('em');
        $cacheService = $this->get('newscoop.cache');
        $adsService = $this->get('ahs_adverts_plugin.ads_service');
        $zendRouter = $this->get('zend_router');

        $criteria = $this->processRequest($request);
        $adsCount = $adsService->countBy(array('is_active' => true));
        $adsInactiveCount = $adsService->countBy(array('is_active' => false));

        $cacheKey = array('classifieds__'.md5(serialize($criteria)), $adsCount, $adsInactiveCount);

        if ($cacheService->contains($cacheKey)) {
            $responseArray =  $cacheService->fetch($cacheKey);
        } else {
            $ads = $em->getRepository('AHS\AdvertsPluginBundle\Entity\Announcement')->getListByCriteria($criteria);

            $pocessed = array();
            foreach ($ads as $ad) {
                $pocessed[] = $this->processAd($ad, $zendRouter);
            }

            $responseArray = array(
                'records' => $pocessed,
                'queryRecordCount' => $ads->count,
                'totalRecordCount'=> count($ads->items)
            );

            $cacheService->save($cacheKey, $responseArray);
        }

        } catch (\Exception $e) { ladybug_dump_die($e->getMessage());}

        return new JsonResponse($responseArray);
    }

    /**
     * @Route("admin/announcements/delete/{id}", options={"expose"=true})
     */
    public function deleteAdAction(Request $request, $id)
    {
        $adsService = $this->get('ahs_adverts_plugin.ads_service');

        return new JsonResponse(array('status' => $adsService->deleteClassified($id)));
    }

    /**
     * @Route("admin/announcements/edit/{id}", options={"expose"=true})
     * @Template()
     */
    public function editAdAction(Request $request, $id = null)
    {
        $em = $this->getDoctrine()->getManager();
        $translator = $this->get('translator');
        $classified = $em->getRepository('AHS\AdvertsPluginBundle\Entity\Announcement')
            ->findOneById($id);

        $form = $this->createForm(new AnnouncementType(), $classified);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();
                $em->flush();

                $this->get('session')->getFlashBag()->add('success', $translator->trans('ads.success.saved'));
            }
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("admin/announcements/settings", options={"expose"=true})
     * @Template()
     */
    public function settingsAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $translator = $this->get('translator');
        $systemPreferences = $this->get('system_preferences_service');
        $form = $this->createForm(new SettingsType(), array(
            'notificationEmail' => $systemPreferences->AdvertsNotificationEmail
        ));

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();
                $systemPreferences->AdvertsNotificationEmail = $data['notificationEmail'];

                $this->get('session')->getFlashBag()->add('success', $translator->trans('ads.success.saved'));
            }
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * Process request parameters
     *
     * @param  Request $request Request object
     *
     * @return AnnouncementCriteria
     */
    private function processRequest(Request $request)
    {
        $criteria = new AnnouncementCriteria();

        if ($request->query->has('sorts')) {
            foreach ($request->get('sorts') as $key => $value) {
                $criteria->orderBy[$key] = $value == '-1' ? 'desc' : 'asc';
            }
        }

        if ($request->query->has('queries')) {
            $queries = $request->query->get('queries');

            if (array_key_exists('search', $queries)) {
                $criteria->query = $queries['search'];
            }

            if (array_key_exists('filter', $queries)) {
                if ($queries['filter'] === 'all') {
                    $criteria->category = 'all';
                } else {
                    $criteria->category = $queries['filter'];
                }
            }

            if (array_key_exists('ad-status', $queries)) {
                foreach ($queries['ad-status'] as $key => $value) {
                    $criteria->status[$key] = $value;
                }
            }
        }

        $criteria->maxResults = $request->query->get('perPage', 10);
        if ($request->query->has('offset')) {
            $criteria->firstResult = $request->query->get('offset');
        }

        return $criteria;
    }

    /**
     * Process single ad
     *
     * @param  Announcement $ad         Announcement
     * @param  Zend_Router  $zendRouter Zend Router
     *
     * @return array
     */
    private function processAd(Announcement $ad, $zendRouter)
    {
        $em = $this->get('em');
        $user = $em->getRepository('Newscoop\Entity\User')
            ->findOneBy(array('id' => $ad->getUser()->getNewscoopUserId()));

        return array(
            'id' => $ad->getId(),
            'name' => $ad->getName(),
            'description' => $ad->getDescription(),
            'publication' => $ad->getPublication()->getName(),
            'price' => $ad->getPrice(),
            'reads' => $ad->getReads(),
            'username' => array(
                    'href' => $zendRouter->assemble(array(
                        'module' => 'admin',
                        'controller' => 'user',
                        'action' => 'edit',
                        'user' => $user->getId(),
                    ), 'default', true),
                    'username' => $user->getUsername(),
            ),
            'created' => $ad->getCreatedAt(),
            'status' => $ad->getIsActive(),
            'links' => array(
                array(
                    'rel' => 'edit',
                    'href' => ""
                ),
                array(
                    'rel' => 'activate',
                    'href' => ""
                ),
                array(
                    'rel' => 'deactivate',
                    'href' => ""
                ),
                array(
                    'rel' => 'delete',
                    'href' => ""
                ),
            )
        );
    }

    private function getCategories()
    {
        $em = $this->container->get('em');

        return $latestAnnouncements = $em->getRepository('AHS\AdvertsPluginBundle\Entity\Category')
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
