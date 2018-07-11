<?php

/**
 * NovaeZMenuManagerBundle.
 *
 * @package   NovaeZMenuManagerBundle
 *
 * @author    Novactive <f.alexandre@novactive.com>
 * @copyright 2018 Novactive
 * @license   https://github.com/Novactive/NovaeZMenuManagerBundle/blob/master/LICENSE
 */

namespace Novactive\EzMenuManager\Twig;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\Core\Helper\TranslationHelper;
use Novactive\EzMenuManagerBundle\Entity\Menu;
use Novactive\EzMenuManagerBundle\Entity\MenuItem\ContentMenuItem;

class MenuManagerExtension extends \Twig_Extension
{
    /** @var TranslationHelper */
    protected $translationHelper;

    /** @var ContentService */
    protected $contentService;

    /**
     * MenuManagerExtension constructor.
     *
     * @param TranslationHelper $translationHelper
     * @param ContentService    $contentService
     */
    public function __construct(TranslationHelper $translationHelper, ContentService $contentService)
    {
        $this->translationHelper = $translationHelper;
        $this->contentService    = $contentService;
    }

    public function getFunctions()
    {
        $functions   = parent::getFunctions();
        $functions[] = new \Twig_SimpleFunction('ezmenumanager_menu_jstree', [$this, 'getMenuJstree']);

        return $functions;
    }

    /**
     * @param Menu $menu
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException
     *
     * @return array
     */
    public function getMenuJstree(Menu $menu)
    {
        $list = [
            [
                'id'     => 0,
                'parent' => '#',
                'text'   => $menu->getName(),
                'state'  => [
                    'disabled' => false,
                    'opened'   => true,
                ],
            ],
        ];

        foreach ($menu->getItems() as $menuItem) {
            $parent = $menuItem->getParent();
            $name   = $menuItem->getName();
            if ($menuItem instanceof ContentMenuItem) {
                $content = $this->contentService->loadContent($menuItem->getContentId());
                $name    = $this->translationHelper->getTranslatedContentName($content);
            }
            $list[] = [
                'id'     => $menuItem->getId(),
                'parent' => $parent ? $parent->getId() : 0,
                'text'   => $name,
                'state'  => [
                    'disabled' => false,
                    'opened'   => true,
                ],
            ];
        }

        return $list;
    }
}