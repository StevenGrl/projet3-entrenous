<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Edition;
use AppBundle\Entity\Event;
use AppBundle\Entity\Notification;
use AppBundle\Service\Mailer;
use AppBundle\Service\SlugService;
use AppBundle\Service\CheckUserRole;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * Edition controller.
 *
 * @Route("edition")
 */
class EditionController extends Controller
{
    /**
     * Lists all edition entities.
     *
     * @Route("/", name="edition_index")
     * @Method("GET")
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $editions = $em->getRepository('AppBundle:Edition')->findAll();

        return $this->render('edition/index.html.twig', array(
            'editions' => $editions,
        ));
    }

    /**
     * @param Edition $edition
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @Route("/participer/{slug}", name="participate_edition")
     */
    public function participateAction(Edition $edition)
    {
        $em = $this->getDoctrine()->getManager();
        $isAParticipant = false;
        foreach ($edition->getParticipants() as $participant) {
            if ($participant->getId() === $this->getUser()->getId()) {
                $this->addFlash('warning', 'Vous participez déjà à cette édition');
                $isAParticipant = true;
                break;
            }
        }
        if (!$isAParticipant) {
            $edition->addParticipant($this->getUser());
            $this->getUser()->addEditionsParticipated($edition);
            $em->flush();
            $this->addFlash('success', 'Vous participez à cette édition');
        }

        return $this->redirectToRoute('edition_show', array('slug' => $edition->getSlug()));
    }

    /**
     * @param Request $request
     * @param Event $event
     * @param CheckUserRole $checkUserRole
     * @param SlugService $slugService
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     *
     * Creates a new edition entity.
     *
     * @Route("/{slug}/new", name="edition_new")
     * @Method({"GET", "POST"})
     */
    public function newAction(Request $request, Event $event, SlugService $slugService, CheckUserRole $checkUserRole)
    {
        $edition = new Edition();
        $isAuthorized = $checkUserRole->checkCreator($this->getUser(), $event);
        if (!$isAuthorized) {
            return $this->redirectToRoute('homepage');
        }
        $edition->setEvent($event);
        $form = $this->createForm('AppBundle\Form\EditionType', $edition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($data->getStartDate() < $data->getEndDate()) {
                $em = $this->getDoctrine()->getManager();
                $edition->setSlug($event->getSlug() . '_' . $slugService->generateSlug($edition->getName()));
                $em->persist($edition);
                $em->flush();
            } else {
                $this->addFlash('danger', 'La date de fin ne peut pas être inférieure à la date de début');
                return $this->render('edition/new.html.twig', array(
                    'edition' => $edition,
                    'form' => $form->createView(),
                    'event' => $event
                ));
            }

            return $this->redirectToRoute('edition_show', array('slug' => $edition->getSlug()));
        }

        return $this->render('edition/new.html.twig', array(
            'edition' => $edition,
            'form' => $form->createView(),
            'event' => $event
        ));
    }

    /**
     *  Finds and displays a edition entity.
     * @param Edition $edition
     * @param CheckUserRole $checkUserRole
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/{slug}", name="edition_show")
     * @Method("GET")
     */
    public function showAction(Edition $edition, CheckUserRole $checkUserRole)
    {
        $deleteForm = $this->createDeleteForm($edition);
        $today = new \DateTime();
        $user = $this->getUser();
        $isManager = $checkUserRole->checkUser($user, $edition);

        return $this->render('edition/show.html.twig', array(
            'edition' => $edition,
            'user' => $user,
            'isManager' => $isManager,
            'delete_form' => $deleteForm->createView(),
            'today' => $today,
        ));
    }

    /**
     * @param Request $request
     * @param Edition $edition
     * @param SlugService $slugService
     * @param CheckUserRole $checkUser
     * @param Mailer $mailer
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     *  Displays a form to edit an existing edition entity.
     *
     * @Route("/{slug}/edit", name="edition_edit")
     * @Method({"GET", "POST"})
     */
    public function editAction(
        Request $request,
        Edition $edition,
        SlugService $slugService,
        CheckUserRole $checkUser,
        Mailer $mailer
    ) {
    
        $currentUser = $this->getUser();
        $isAuthorized = $checkUser->checkUser($currentUser, $edition);
        if (!$isAuthorized) {
            return $this->redirectToRoute('edition_show', array('slug' => $edition->getSlug()));
        }
        $deleteForm = $this->createDeleteForm($edition);
        $editForm = $this->createForm('AppBundle\Form\EditionType', $edition);
        $editForm->handleRequest($request);

        $notification = new Notification();
        $notification->setEdition($edition);
        $notificationForm = $this->createForm('AppBundle\Form\NotificationType', $notification);
        $notificationForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $data = $editForm->getData();
            if ($data->getStartDate() < $data->getEndDate()) {
                $edition->setSlug(
                    $edition->getEvent()->getSlug() . '_' . $slugService->generateSlug($edition->getName())
                );
                $this->getDoctrine()->getManager()->flush();
            } else {
                $this->addFlash('danger', 'La date de fin ne peut pas être inférieure à la date de début');
            }

            return $this->redirectToRoute('edition_edit', array('slug' => $edition->getSlug()));
        }

        if ($notificationForm->isSubmitted() && $notificationForm->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($notification);
            $em->flush();
            foreach ($edition->getEvent()->getFollowers() as $user) {
                $mailer->notifMail(
                    $edition->getEvent()->getCreator(),
                    $user,
                    "Une nouvelle a été publiée concernant cet évènement",
                    'notification',
                    $edition->getEvent()
                );
            }
            return $this->redirectToRoute('edition_edit', array('slug' => $edition->getSlug()));
        }

        $em = $this->getDoctrine()->getManager();
        $notifications = $em->getRepository(Notification::class)->findByEdition($edition->getId());

        return $this->render('edition/edit.html.twig', array(
            'edition' => $edition,
            'notification' => $notification,
            'notifications' => $notifications,
            'edit_form' => $editForm->createView(),
            'notification_form' => $notificationForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a edition entity.
     *
     * @Route("/{id}", name="edition_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, Edition $edition)
    {
        $form = $this->createDeleteForm($edition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($edition);
            $em->flush();
        }

        return $this->redirectToRoute('event_index');
    }

    /**
     * Creates a form to delete a edition entity.
     *
     * @param Edition $edition The edition entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm(Edition $edition)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('edition_delete', array('id' => $edition->getId())))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }
}
