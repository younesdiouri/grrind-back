<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Les faits métier s'observent ici mais ne se réécrivent jamais dans EasyAdmin. */
/** @extends AbstractCrudController<object> */
abstract class ReadOnlyCrudController extends AbstractCrudController
{
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        throw new AccessDeniedHttpException('Cette ressource est en lecture seule.');
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        throw new AccessDeniedHttpException('Cette ressource est en lecture seule.');
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        throw new AccessDeniedHttpException('Cette ressource est en lecture seule.');
    }
}
