<?php

/**
 * NovaeZMenuManagerBundle.
 *
 * @package   NovaeZMenuManagerBundle
 *
 * @author    Novactive <f.alexandre@novactive.com>
 * @copyright 2019 Novactive
 * @license   https://github.com/Novactive/NovaeZMenuManagerBundle/blob/master/LICENSE
 */

namespace Novactive\EzMenuManagerBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\AdminUi\Controller\Controller;
use Ibexa\Contracts\AdminUi\Notification\NotificationHandlerInterface;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Ibexa\Core\Base\Exceptions\UnauthorizedException;
use Novactive\EzMenuManager\Form\Type\MenuDeleteType;
use Novactive\EzMenuManager\Form\Type\MenuSearchType;
use Novactive\EzMenuManager\Form\Type\MenuType;
use Novactive\EzMenuManagerBundle\Entity\Menu;
use Novactive\EzMenuManagerBundle\Entity\MenuSearch;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use PDO;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class AdminController.
 *
 * @Route("/menu-manager/admin")
 *
 * @package Novactive\EzMenuManagerBundle\Controller
 */
class AdminController extends Controller
{
    public const RESULTS_PER_PAGE = 20;

    protected TranslatorInterface $translator;
    protected NotificationHandlerInterface $notificationHandler;
    protected EntityManagerInterface $em;
    protected ConfigResolverInterface $configResolver;
    protected PermissionResolver $permissionResolver;

    /**
     * AdminController constructor.
     */
    public function __construct(
        TranslatorInterface $translator,
        NotificationHandlerInterface $notificationHandler,
        EntityManagerInterface $em,
        ConfigResolverInterface $configResolver,
        PermissionResolver $permissionResolver
    ) {
        $this->translator = $translator;
        $this->notificationHandler = $notificationHandler;
        $this->em = $em;
        $this->configResolver = $configResolver;
        $this->permissionResolver = $permissionResolver;
    }

    /**
     * @Route("/list/{page}", name="menu_manager.menu_list", requirements={"page" = "\d+"})
     *
     * @param int $page
     *
     * @SuppressWarnings(PHPMD.IfStatementAssignment)
     *
     * @return Response
     */
    public function listAction(Request $request, $page = 1)
    {
        if (!$this->permissionResolver->hasAccess('menu_manager', 'list')) {
            throw new UnauthorizedException('menu_manager', 'list', []);
        }
        $queryBuilder = $this->em->createQueryBuilder()
                           ->select('m')
                           ->from(Menu::class, 'm')
                            ->orderBy('m.name');

        $search = new MenuSearch();
        $searchForm = $this->createForm(MenuSearchType::class, $search, ['method' => 'get']);
        $searchForm->handleRequest($request);
        if ($searchForm->isSubmitted() && $searchForm->isValid()) {
            /** @var MenuSearch $search */
            $search = $searchForm->getData();
            if ($type = $search->getType()) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('m.type', ':type'));
                $queryBuilder->setParameter(':type', $type, PDO::PARAM_STR);
            }
            if ($name = $search->getName()) {
                $queryBuilder->andWhere($queryBuilder->expr()->like('m.name', ':name'));
                $queryBuilder->setParameter(':name', "%{$name}%", PDO::PARAM_STR);
            }
        }

        $pagerfanta = new Pagerfanta(
            new DoctrineORMAdapter($queryBuilder)
        );

        $pagerfanta->setMaxPerPage(self::RESULTS_PER_PAGE);
        $pagerfanta->setCurrentPage(min($page, $pagerfanta->getNbPages()));

        /** @var Menu[] $menus */
        $menus = $pagerfanta->getCurrentPageResults();
        $menuIds = $this->getMenusIds($menus);
        $formData = [
            'menus' => array_combine($menuIds, array_fill_keys($menuIds, false)),
        ];

        $menuDeleteForm = $this->createForm(MenuDeleteType::class, $formData);

        return $this->render(
            '@ibexadesign/menu_manager/admin/list.html.twig',
            [
                'search_form' => $searchForm->createView(),
                'pager' => $pagerfanta,
                'menu_delete_form' => $menuDeleteForm->createView(),
                'menu_types' => $this->configResolver->getParameter('menu_types', 'nova_menu_manager') ?? [],
            ]
        );
    }

    /**
     * @param Menu[] $menus
     *
     * @return array
     */
    protected function getMenusIds($menus)
    {
        $ids = [];
        foreach ($menus as $menu) {
            $ids[] = $menu->getId();
        }

        return $ids;
    }

    /**
     * @Route("/new", name="menu_manager.menu_new")
     *
     * @return RedirectResponse|Response
     */
    public function newAction(Request $request)
    {
        if (!$this->permissionResolver->hasAccess('menu_manager', 'new')) {
            throw new UnauthorizedException('menu_manager', 'new', []);
        }
        $menu = new Menu();
        $menu->setRootLocationId(
            $this->configResolver->getParameter('content.tree_root.location_id')
        );

        return $this->editAction($request, $menu);
    }

    /**
     * @Route("/edit/{menu}", name="menu_manager.menu_edit")
     *
     * @return RedirectResponse|Response
     */
    public function editAction(Request $request, Menu $menu)
    {
        if (!$this->permissionResolver->hasAccess('menu_manager', 'edit')) {
            throw new UnauthorizedException('menu_manager', 'edit', []);
        }

        $form = $this->createForm(MenuType::class, $menu);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Menu $menu */
            $menu = $form->getData();
            $menu->assignPositions();
            $this->em->persist($menu);
            $this->em->flush();

            $this->notificationHandler->success(
                $this->translator->trans('menu.notification.saved', [], 'menu_manager')
            );

            return $this->redirect($this->generateUrl('menu_manager.menu_list'));
        }

        return $this->render(
            '@ibexadesign/menu_manager/admin/edit.html.twig',
            [
                'form' => $form->createView(),
                'title' => $menu->getId() ? $menu->getName() : $this->translator->trans('menu.new', [], 'menu_manager'),
            ]
        );
    }

    /**
     * @Route("/delete", name="menu_manager.menu_delete", methods={"POST"})
     */
    public function deleteAction(Request $request)
    {
        if (!$this->permissionResolver->hasAccess('menu_manager', 'delete')) {
            throw new UnauthorizedException('menu_manager', 'delete', []);
        }
        $form = $this->createForm(MenuDeleteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $formData = $form->getData();
            $menuIds = array_keys($formData['menus']);
            foreach ($menuIds as $menuId) {
                $menu = $this->em->find(Menu::class, $menuId);
                $this->em->remove($menu);
            }
            $this->em->flush();

            $this->notificationHandler->success(
                $this->translator->trans('menu.notification.deleted', [], 'menu_manager')
            );
        }

        return $this->redirectToRoute('menu_manager.menu_list');
    }
}
