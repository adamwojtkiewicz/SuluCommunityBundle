<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\CommunityBundle\Controller;

use Sulu\Bundle\CommunityBundle\DependencyInjection\Configuration;
use Sulu\Bundle\CommunityBundle\Entity\BlacklistItem;
use Sulu\Bundle\CommunityBundle\Entity\BlacklistUser;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles user confirmations for administrators.
 */
class BlacklistConfirmationController extends AbstractController
{
    /**
     * Confirms user with given token.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function confirmAction(Request $request): Response
    {
        $entityManager = $this->get('doctrine.orm.entity_manager');
        $repository = $this->get('sulu_community.blacklisting.user_repository');

        /** @var BlacklistUser|null $blacklistUser */
        $blacklistUser = $repository->findByToken($request->get('token'));

        if (null === $blacklistUser) {
            throw new NotFoundHttpException();
        }

        $blacklistUser->confirm();
        $entityManager->flush();

        $communityManager = $this->getCommunityManager($blacklistUser->getWebspaceKey());

        /** @var User $user */
        $user = $blacklistUser->getUser();
        $communityManager->sendEmails(Configuration::TYPE_BLACKLIST_CONFIRMED, $user);

        return $this->renderTemplate(
            Configuration::TYPE_BLACKLIST_CONFIRMED,
            ['user' => $blacklistUser->getUser()]
        );
    }

    /**
     * Denies user with given token.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function denyAction(Request $request): Response
    {
        $entityManager = $this->get('doctrine.orm.entity_manager');
        $repository = $this->get('sulu_community.blacklisting.user_repository');
        $itemRepository = $this->get('sulu_community.blacklisting.item_repository');
        $itemManager = $this->get('sulu_community.blacklisting.item_manager');

        /** @var BlacklistUser|null $blacklistUser */
        $blacklistUser = $repository->findByToken($request->get('token'));

        if (null === $blacklistUser) {
            throw new NotFoundHttpException();
        }

        /** @var User $user */
        $user = $blacklistUser->getUser();
        $blacklistUser->deny();

        $communityManager = $this->getCommunityManager($blacklistUser->getWebspaceKey());
        if (true === $communityManager->getConfigTypeProperty(
                Configuration::TYPE_BLACKLIST_DENIED,
                Configuration::DELETE_USER
            )
        ) {
            $entityManager->remove($user->getContact());
            $entityManager->remove($user);
            $entityManager->remove($blacklistUser);
        }

        $item = $itemRepository->findOneByPattern($user->getEmail());

        if (!$item) {
            $item = $itemManager->create();
        }

        $item->setType(BlacklistItem::TYPE_BLOCK)
            ->setPattern($user->getEmail());

        $entityManager->flush();

        $communityManager->sendEmails(Configuration::TYPE_BLACKLIST_DENIED, $user);

        return $this->renderTemplate(
            Configuration::TYPE_BLACKLIST_DENIED,
            ['user' => $blacklistUser->getUser()]
        );
    }
}
