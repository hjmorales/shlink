<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Service;

use Doctrine\ORM\EntityManager;
use Laminas\Stdlib\ArrayUtils;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shlinkio\Shlink\Common\Util\DateRange;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Entity\Tag;
use Shlinkio\Shlink\Core\Entity\Visit;
use Shlinkio\Shlink\Core\EventDispatcher\ShortUrlVisited;
use Shlinkio\Shlink\Core\Exception\ShortUrlNotFoundException;
use Shlinkio\Shlink\Core\Exception\TagNotFoundException;
use Shlinkio\Shlink\Core\Model\ShortUrlIdentifier;
use Shlinkio\Shlink\Core\Model\Visitor;
use Shlinkio\Shlink\Core\Model\VisitsParams;
use Shlinkio\Shlink\Core\Repository\ShortUrlRepositoryInterface;
use Shlinkio\Shlink\Core\Repository\TagRepository;
use Shlinkio\Shlink\Core\Repository\VisitRepository;
use Shlinkio\Shlink\Core\Service\VisitsTracker;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use ShlinkioTest\Shlink\Core\Util\ApiKeyHelpersTrait;

use function Functional\map;
use function range;

class VisitsTrackerTest extends TestCase
{
    use ApiKeyHelpersTrait;
    use ProphecyTrait;

    private VisitsTracker $visitsTracker;
    private ObjectProphecy $em;
    private ObjectProphecy $eventDispatcher;

    public function setUp(): void
    {
        $this->em = $this->prophesize(EntityManager::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $this->visitsTracker = new VisitsTracker($this->em->reveal(), $this->eventDispatcher->reveal(), true);
    }

    /** @test */
    public function trackPersistsVisit(): void
    {
        $shortCode = '123ABC';

        $this->em->persist(Argument::that(fn (Visit $visit) => $visit->setId('1')))->shouldBeCalledOnce();
        $this->em->flush()->shouldBeCalledOnce();

        $this->visitsTracker->track(new ShortUrl($shortCode), Visitor::emptyInstance());

        $this->eventDispatcher->dispatch(Argument::type(ShortUrlVisited::class))->shouldHaveBeenCalled();
    }

    /**
     * @test
     * @dataProvider provideAdminApiKeys
     */
    public function infoReturnsVisitsForCertainShortCode(?ApiKey $apiKey): void
    {
        $shortCode = '123ABC';
        $spec = $apiKey === null ? null : $apiKey->spec();
        $repo = $this->prophesize(ShortUrlRepositoryInterface::class);
        $count = $repo->shortCodeIsInUse($shortCode, null, $spec)->willReturn(true);
        $this->em->getRepository(ShortUrl::class)->willReturn($repo->reveal())->shouldBeCalledOnce();

        $list = map(range(0, 1), fn () => new Visit(new ShortUrl(''), Visitor::emptyInstance()));
        $repo2 = $this->prophesize(VisitRepository::class);
        $repo2->findVisitsByShortCode($shortCode, null, Argument::type(DateRange::class), 1, 0, $spec)->willReturn(
            $list,
        );
        $repo2->countVisitsByShortCode($shortCode, null, Argument::type(DateRange::class), $spec)->willReturn(1);
        $this->em->getRepository(Visit::class)->willReturn($repo2->reveal())->shouldBeCalledOnce();

        $paginator = $this->visitsTracker->info(new ShortUrlIdentifier($shortCode), new VisitsParams(), $apiKey);

        self::assertEquals($list, ArrayUtils::iteratorToArray($paginator->getCurrentItems()));
        $count->shouldHaveBeenCalledOnce();
    }

    /** @test */
    public function throwsExceptionWhenRequestingVisitsForInvalidShortCode(): void
    {
        $shortCode = '123ABC';
        $repo = $this->prophesize(ShortUrlRepositoryInterface::class);
        $count = $repo->shortCodeIsInUse($shortCode, null, null)->willReturn(false);
        $this->em->getRepository(ShortUrl::class)->willReturn($repo->reveal())->shouldBeCalledOnce();

        $this->expectException(ShortUrlNotFoundException::class);
        $count->shouldBeCalledOnce();

        $this->visitsTracker->info(new ShortUrlIdentifier($shortCode), new VisitsParams());
    }

    /** @test */
    public function throwsExceptionWhenRequestingVisitsForInvalidTag(): void
    {
        $tag = 'foo';
        $apiKey = new ApiKey();
        $repo = $this->prophesize(TagRepository::class);
        $tagExists = $repo->tagExists($tag, $apiKey)->willReturn(false);
        $getRepo = $this->em->getRepository(Tag::class)->willReturn($repo->reveal());

        $this->expectException(TagNotFoundException::class);
        $tagExists->shouldBeCalledOnce();
        $getRepo->shouldBeCalledOnce();

        $this->visitsTracker->visitsForTag($tag, new VisitsParams(), $apiKey);
    }

    /**
     * @test
     * @dataProvider provideAdminApiKeys
     */
    public function visitsForTagAreReturnedAsExpected(?ApiKey $apiKey): void
    {
        $tag = 'foo';
        $repo = $this->prophesize(TagRepository::class);
        $tagExists = $repo->tagExists($tag, $apiKey)->willReturn(true);
        $getRepo = $this->em->getRepository(Tag::class)->willReturn($repo->reveal());

        $spec = $apiKey === null ? null : $apiKey->spec();
        $list = map(range(0, 1), fn () => new Visit(new ShortUrl(''), Visitor::emptyInstance()));
        $repo2 = $this->prophesize(VisitRepository::class);
        $repo2->findVisitsByTag($tag, Argument::type(DateRange::class), 1, 0, $spec)->willReturn($list);
        $repo2->countVisitsByTag($tag, Argument::type(DateRange::class), $spec)->willReturn(1);
        $this->em->getRepository(Visit::class)->willReturn($repo2->reveal())->shouldBeCalledOnce();

        $paginator = $this->visitsTracker->visitsForTag($tag, new VisitsParams(), $apiKey);

        self::assertEquals($list, ArrayUtils::iteratorToArray($paginator->getCurrentItems()));
        $tagExists->shouldHaveBeenCalledOnce();
        $getRepo->shouldHaveBeenCalledOnce();
    }
}
