<?php
declare(strict_types=1);
namespace App\Controller;
use App\Application\Administration\DeleteUserAccount; use App\Entity\Feed; use App\Entity\FeedIncident; use App\Entity\Subscription; use App\Entity\User; use App\Message\CollectFeedMessage; use Doctrine\ORM\EntityManagerInterface; use Symfony\Bundle\FrameworkBundle\Controller\AbstractController; use Symfony\Component\HttpFoundation\Request; use Symfony\Component\HttpFoundation\Response; use Symfony\Component\Messenger\MessageBusInterface; use Symfony\Component\Routing\Attribute\Route; use Symfony\Component\Uid\Uuid;
final class AdminController extends AbstractController
{
 #[Route('/admin/feeds/{id}/delete', name:'app_admin_feed_delete', methods:['POST'])]
 public function deleteFeed(string $id, Request $r, EntityManagerInterface $em):Response
 {
  $this->denyAccessUnlessGranted('ROLE_ADMIN');
  try { $feed=$em->getRepository(Feed::class)->find(Uuid::fromString($id)); } catch (\InvalidArgumentException) { $feed=null; }
  if (!$feed instanceof Feed) throw $this->createNotFoundException();
  if (!$this->isCsrfTokenValid('delete-feed-'.$id,$r->request->getString('_token'))) throw $this->createAccessDeniedException();
  $scope=$r->request->getString('scope');
  if (!in_array($scope,['incident','unused'],true)) throw $this->createAccessDeniedException();
  $em->wrapInTransaction(function () use ($em, $feed, $scope): void {
   $em->lock($feed, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
   if ('unused' === $scope && $em->getRepository(Subscription::class)->count(['feed'=>$feed]) > 0) throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Ce flux possède désormais un abonnement.');
   if ('incident' === $scope && !$em->getRepository(FeedIncident::class)->findOneBy(['feed'=>$feed,'acknowledgedAt'=>null]) instanceof FeedIncident) throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Cet incident n’est plus ouvert.');
   $em->remove($feed);
   $em->flush();
  });
  $this->addFlash('success','Flux et données associées supprimés pour tous les comptes.');
  return $this->redirectToRoute('app_admin');
 }
 #[Route('/admin', name:'app_admin', methods:['GET'])] public function index(EntityManagerInterface $em, \App\Application\Administration\RegistrationLimit $registrationLimit):Response {
  $this->denyAccessUnlessGranted('ROLE_ADMIN');
  $unusedFeeds=$em->createQuery('SELECT f FROM App\Entity\Feed f WHERE NOT EXISTS (SELECT s.id FROM App\Entity\Subscription s WHERE s.feed = f) ORDER BY f.createdAt DESC')->getResult();
  $users=$em->getRepository(User::class)->findBy([],['createdAt'=>'DESC']); $quotaCounts=[];
  foreach ($users as $user) $quotaCounts[(string)$user->getId()]=$em->getRepository(Subscription::class)->count(['user'=>$user]);
  $start=(new \DateTimeImmutable('today'))->modify('-29 days'); $stats=[];
  foreach (['flux'=>['feed','created_at'], 'articles'=>['article','collected_at'], 'comptes'=>['app_user','created_at']] as $label=>[$table,$column]) {
   $rows=$em->getConnection()->fetchAllAssociative("SELECT DATE($column) AS day, COUNT(*) AS total FROM $table WHERE $column >= ? AND $column < ? GROUP BY DATE($column)", [$start->format('Y-m-d H:i:s'), $start->modify('+30 days')->format('Y-m-d H:i:s')]);
   $counts=array_column($rows,'total','day'); $days=[]; for($i=0;$i<30;++$i){$day=$start->modify('+'.$i.' days')->format('Y-m-d');$days[]=['date'=>$day,'count'=>(int)($counts[$day]??0)];} $stats[$label]=$days;
  }
  $rows=$em->getConnection()->fetchAllAssociative('SELECT day, total FROM notification_daily_count WHERE day >= ? AND day < ?', [$start->format('Y-m-d'), $start->modify('+30 days')->format('Y-m-d')]);
  $counts=array_column($rows,'total','day'); $days=[]; for($i=0;$i<30;++$i){$day=$start->modify('+'.$i.' days')->format('Y-m-d');$days[]=['date'=>$day,'count'=>(int)($counts[$day]??0)];} $stats['notifications envoyées']=$days;
  $uuid='LOWER(CONCAT(SUBSTR(HEX(%s),1,8),\'-\',SUBSTR(HEX(%s),9,4),\'-\',SUBSTR(HEX(%s),13,4),\'-\',SUBSTR(HEX(%s),17,4),\'-\',SUBSTR(HEX(%s),21)))';$syncCategories=$em->getConnection()->fetchAllAssociative(sprintf('SELECT '.sprintf($uuid,'c.id','c.id','c.id','c.id','c.id').' AS id, c.name, u.email_normalized AS owner, COUNT(DISTINCT s.feed_id) AS feeds FROM category c INNER JOIN app_user u ON u.id=c.user_id LEFT JOIN subscription s ON s.category_id=c.id GROUP BY c.id,c.name,u.email_normalized ORDER BY c.name,u.email_normalized'));$syncFeeds=$em->getConnection()->fetchAllAssociative(sprintf('SELECT '.sprintf($uuid,'id','id','id','id','id').' AS id, COALESCE(title,canonical_url) AS label FROM feed ORDER BY label'));
  $queueCount=(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['default']);$heartbeat=$em->getConnection()->fetchOne('SELECT last_seen_at FROM messenger_worker_heartbeat WHERE name = ?', ['async']);$workerActive=is_string($heartbeat) && new \DateTimeImmutable($heartbeat) >= (new \DateTimeImmutable())->modify('-90 seconds');
  return $this->render('admin/index.html.twig',['users'=>$users,'quotaCounts'=>$quotaCounts,'stats'=>$stats,'registrationLimit'=>$registrationLimit->effectiveLimit(),'registrationLimitLocked'=>null !== $registrationLimit->environmentLimit(),'unusedFeeds'=>$unusedFeeds,'incidents'=>$em->getRepository(FeedIncident::class)->findBy(['acknowledgedAt'=>null]),'feedCount'=>$em->getRepository(Feed::class)->count([]),'subscriptionCount'=>$em->getRepository(Subscription::class)->count([]),'syncCategories'=>$syncCategories,'syncFeeds'=>$syncFeeds,'queueCount'=>$queueCount,'workerActive'=>$workerActive]);
 }
 #[Route('/admin/registration-limit', name:'app_admin_registration_limit', methods:['POST'])] public function registrationLimit(Request $r, \App\Application\Administration\RegistrationLimit $limit):Response {
  $this->denyAccessUnlessGranted('ROLE_ADMIN');
  if (!$this->isCsrfTokenValid('registration-limit',$r->request->getString('_token'))) throw $this->createAccessDeniedException();
  if (null !== $limit->environmentLimit()) throw $this->createAccessDeniedException('La limite est définie par l’environnement.');
  $value=$r->request->getString('account_limit');
  try { if ('' !== $value && (!ctype_digit($value) || (string)(int)$value !== $value)) throw new \InvalidArgumentException('Saisissez un entier positif.'); $limit->update('' === $value ? null : (int)$value); $this->addFlash('success','Limite d’inscriptions enregistrée.'); }
  catch (\InvalidArgumentException $e) { $this->addFlash('error',$e->getMessage()); }
  return $this->redirectToRoute('app_admin', status:Response::HTTP_SEE_OTHER);
 }
 #[Route('/admin/incidents/{id}/retry', name:'app_admin_incident_retry', methods:['POST'])] public function retry(string $id, Request $r, EntityManagerInterface $em, MessageBusInterface $bus, \App\MessageHandler\CollectFeedHandler $collect, \App\Application\Notification\IncidentMessage $incidentMessage):Response {
  $this->denyAccessUnlessGranted('ROLE_ADMIN');
  try{$incident=$em->getRepository(FeedIncident::class)->find(Uuid::fromString($id));}catch(\InvalidArgumentException){$incident=null;}
  if(!$incident instanceof FeedIncident)throw $this->createNotFoundException();
  if(!$this->isCsrfTokenValid('retry-incident-'.$id,$r->request->getString('_token')))throw $this->createAccessDeniedException();
  if(null !== $incident->getAcknowledgedAt()) throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Cet incident n’est plus ouvert.');
  if ('application/json' === $r->headers->get('Accept')) {
   $logs = ['Vérification de l’adresse et collecte du flux…'];
   try {
    $collect(new CollectFeedMessage((string)$incident->getFeed()->getId()));
    $logs[] = 'Collecte terminée avec succès.';
    $logs[] = 'L’incident reste ouvert jusqu’à son acquittement.';
    return $this->json(['success' => true, 'logs' => $logs]);
   } catch (\Throwable $error) {
    $logs[] = $incidentMessage->describe($error->getMessage());
    $logs[] = 'La collecte a échoué. L’incident reste ouvert.';
    return $this->json(['success' => false, 'logs' => $logs]);
   }
  }
  $bus->dispatch(new CollectFeedMessage((string)$incident->getFeed()->getId()));
  $this->addFlash('success','Nouvelle collecte placée en file. L’incident reste ouvert jusqu’à son acquittement.');
  return $this->redirectToRoute('app_admin');
 }
 #[Route('/admin/users/{id}', name:'app_admin_user_update', methods:['POST'])] public function update(string $id, Request $r, EntityManagerInterface $em):Response { try{$u=$em->getRepository(User::class)->find(Uuid::fromString($id));}catch(\InvalidArgumentException){$u=null;} if(!$u instanceof User)throw $this->createNotFoundException(); if(!$this->isCsrfTokenValid('admin-user-'.$id,$r->request->getString('_token')))throw $this->createAccessDeniedException(); '1'===$r->request->getString('enabled')?$u->enable():$u->disable(); '1'===$r->request->getString('admin')?$u->addRole('ROLE_ADMIN'):$u->removeAdminRole(); $limit=$r->request->getString('subscription_limit'); $u->setSubscriptionLimit(''===$limit?null:(int)$limit); $em->flush(); return $this->redirectToRoute('app_admin'); }
 #[Route('/admin/incidents/{id}/acknowledge', name:'app_admin_incident_acknowledge', methods:['POST'])] public function acknowledge(string $id, Request $r, EntityManagerInterface $em):Response { try{$incident=$em->getRepository(FeedIncident::class)->find(Uuid::fromString($id));}catch(\InvalidArgumentException){$incident=null;} if(!$incident instanceof FeedIncident)throw $this->createNotFoundException(); if(!$this->isCsrfTokenValid('incident-'.$id,$r->request->getString('_token')))throw $this->createAccessDeniedException(); $incident->acknowledge();$em->flush();return $this->redirectToRoute('app_admin'); }
 #[Route('/admin/users/{id}/delete', name:'app_admin_user_delete', methods:['POST'])] public function delete(string $id, Request $r, EntityManagerInterface $em, DeleteUserAccount $deletion):Response { try{$u=$em->getRepository(User::class)->find(Uuid::fromString($id));}catch(\InvalidArgumentException){$u=null;} if(!$u instanceof User)throw $this->createNotFoundException(); if(!$this->isCsrfTokenValid('delete-user-'.$id,$r->request->getString('_token')) || 'SUPPRIMER' !== $r->request->getString('confirmation'))throw $this->createAccessDeniedException(); if($this->getUser() instanceof User && $this->getUser()->getId()->equals($u->getId())) throw $this->createAccessDeniedException('Un administrateur ne peut pas supprimer son propre compte.'); try{$deletion->delete($u);}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());return $this->redirectToRoute('app_admin');}$this->addFlash('success','Compte et données privées supprimés.');return $this->redirectToRoute('app_admin'); }
}
