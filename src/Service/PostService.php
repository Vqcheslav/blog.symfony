<?php

namespace App\Service;

use App\Entity\Posts;
use App\Entity\AdditionalInfoPosts;
use App\Entity\RatingPosts;
use App\Repository\PostsRepository;
use App\Repository\RatingPostsRepository;
use App\Repository\AdditionalInfoPostsRepository;
use Doctrine\Persistence\ManagerRegistry;


class PostService
{
    private ManagerRegistry $doctrine;
    private PostsRepository $postsRepository;
    private RatingPostsRepository $ratingPostsRepository;
    private AdditionalInfoPostsRepository $additionalInfoPostsRepository;
    private $entityManager;

    public function __construct(      
        ManagerRegistry $doctrine,
        PostsRepository $postsRepository,
        RatingPostsRepository $ratingPostsRepository,
        AdditionalInfoPostsRepository $additionalInfoPostsRepository
        )
    {
        $this->postsRepository = $postsRepository;
        $this->ratingPostsRepository = $ratingPostsRepository;
        $this->additionalInfoPostsRepository = $additionalInfoPostsRepository;
        $this->doctrine = $doctrine;
        $this->entityManager = $this->doctrine->getManager();
    }

    public function add(int $userId, string $title, string $content)
    {
        $entityManager = $this->doctrine->getManager();
        $post = new Posts();
        $post->setTitle($title);
        $post->setUserId($userId);
        $post->setContent($content);
        $post->setDateTime(time());
        $entityManager->persist($post);
        $entityManager->flush();

        $postInfo = new AdditionalInfoPosts();
        $postInfo->setRating('0.0');
        $postInfo->setPostId($post->getId());
        $postInfo->setCountComments(0);
        $postInfo->setCountRatings(0);
        $entityManager->persist($postInfo);
        $entityManager->flush();
    }

    /**
     * @return float Returns an float number - rating of post
     */
    private function countRating(int $postId)
    {
        $rating = 0.0;
        $i = 0;
        $allRatingsPost = $this->ratingPostsRepository->findBy(['postId' => $postId]);
        foreach ($allRatingsPost as $ratingPost)
        {
            $i++;
            $rating += $ratingPost->getRating();
        }
        $rating = round($rating / $i, 1);
        return $rating;
    }

    public function addRating(int $userId, int $postId, int $rating)
    {
        $ratingPost = new RatingPosts();
        $ratingPost->setPostId($postId);
        $ratingPost->setUserId($userId);
        $ratingPost->setRating($rating);
        $this->entityManager->persist($ratingPost);
        $this->entityManager->flush();

        $infoPost = $this->additionalInfoPostsRepository->find($postId);
        $infoPost->setCountRatings($infoPost->getCountRatings() + 1);

        $generalRatingPost = $this->countRating($postId);
        $infoPost->setRating((string) $generalRatingPost);
        $this->entityManager->flush();
    }

    public function isUserAddRating(int $userId, int $postId): bool
    {
        if ($this->ratingPostsRepository->findOneBy(
            [
                'userId' => $userId,
                'postId' => $postId
            ]
        ))
        {
            return true;
        }
        return false;
    }

    /**
     * @return Posts[] Returns an array of Posts objects
     */
    public function getLastPosts(int $amountOfPosts)
    {
        return $this->postsRepository->getLastPosts($amountOfPosts);
    }

    /**
     * @return Posts[] Returns an array of Posts objects
     */
    public function getMoreTalkedPosts(int $amountOfPosts)
    {
        return $this->postsRepository->getMoreTalkedPosts($amountOfPosts);
    }

    /**
     * @return Posts[] Returns an array of Posts objects
     */
    public function getPosts(int $numberOfPosts, int $page)
    {
        $moreThanMinId = $page * $numberOfPosts - $numberOfPosts;

        return $this->postsRepository->getPosts($numberOfPosts, $moreThanMinId);
    }

    /**
     * @return Posts Returns a Posts object
     */
    public function getPostById(int $postId)
    {
        return $this->postsRepository->getPostById($postId);
    }

    /**
     * @return Posts[] Returns an array of Posts objects
     */
    public function getPostsByUserId(int $userId)
    {
        return $this->postsRepository->getPostsByUserId($userId);
    }

    /**
     * @return Posts[] Returns an array of Posts objects
     */
    public function getLikedPostsByUserId(int $userId)
    {
        return $this->postsRepository->getLikedPostsByUserId($userId);
    }

    public function delete($post)
    {
        $postId = $post->getId();
        $this->entityManager->remove($post);
        $this->entityManager->flush();

        $infoPost = $this->additionalInfoPostsRepository->find($postId);
        $this->entityManager->remove($infoPost);
        $this->entityManager->flush();
    }
}