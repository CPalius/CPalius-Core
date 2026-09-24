<?php

declare(strict_types=1);

namespace Modules\Forum\Command;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Repository\ForumSectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'forum:seed-mail', description: 'Create the Mail and DNS forum tree when those boards are missing.')]
final class SeedMailForumCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ForumSectionRepository $sections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Board locale', 'tr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getOption('locale');
        $created = 0;

        foreach ($this->tree() as $index => $division) {
            $parent = $this->ensure(
                $division['code'],
                $division['slug'],
                $locale,
                $division['title'],
                $division['description'],
                ForumSectionType::Division,
                null,
                $index,
            );
            $created += $parent['created'];
            foreach ($division['categories'] as $categoryIndex => $category) {
                $categoryNode = $this->ensure(
                    $category['code'],
                    $category['slug'],
                    $locale,
                    $category['title'],
                    $category['description'],
                    ForumSectionType::Category,
                    $parent['section'],
                    $categoryIndex,
                );
                $created += $categoryNode['created'];
                foreach ($category['boards'] as $boardIndex => $board) {
                    $leaf = $this->ensure(
                        $board['code'],
                        $board['slug'],
                        $locale,
                        $board['title'],
                        $board['description'],
                        ForumSectionType::Subcategory,
                        $categoryNode['section'],
                        $boardIndex,
                    );
                    $created += $leaf['created'];
                }
            }
        }

        $io->success($created.' board(s) created.');

        return Command::SUCCESS;
    }

    /**
     * @return array{section: ForumSection, created: int}
     */
    private function ensure(
        string $code,
        string $slug,
        string $locale,
        string $title,
        string $description,
        ForumSectionType $type,
        ?ForumSection $parent,
        int $sort,
    ): array {
        $existing = $this->sections->findOneByCodeAndLocale($code, $locale);
        if ($existing instanceof ForumSection) {
            return ['section' => $existing, 'created' => 0];
        }

        $section = new ForumSection($code, $slug, $locale, $title);
        $section->setSectionType($type);
        $section->setDescription($description);
        $section->setSortOrder($sort);
        $section->setParent($parent);
        $this->em->persist($section);
        $this->em->flush();
        $path = $parent instanceof ForumSection ? $parent->getParentPath() : '/';
        $section->setParentPath($path.$section->getId().'/');
        $this->em->flush();

        return ['section' => $section, 'created' => 1];
    }

    /**
     * @return list<array{code: string, slug: string, title: string, description: string, categories: list<array{code: string, slug: string, title: string, description: string, boards: list<array{code: string, slug: string, title: string, description: string}>}>}>
     */
    private function tree(): array
    {
        return [
            [
                'code' => 'mail',
                'slug' => 'posta',
                'title' => 'Posta',
                'description' => 'Teslim, kimlik doğrulama ve sunucu sorunları.',
                'categories' => [
                    [
                        'code' => 'mail-teslim',
                        'slug' => 'teslim-ve-spam',
                        'title' => 'Teslim ve spam',
                        'description' => 'Postanın neden düştüğü ve nasıl temizleneceği.',
                        'boards' => [
                            ['code' => 'mail-spam', 'slug' => 'spam-skoru', 'title' => 'Spam skoru', 'description' => 'Skor sonuçları, kara listeler ve iyileştirme.'],
                            ['code' => 'mail-auth', 'slug' => 'spf-dkim-dmarc', 'title' => 'SPF, DKIM ve DMARC', 'description' => 'Kimlik kayıtları ve hizalama.'],
                            ['code' => 'mail-bounce', 'slug' => 'geri-donen-postalar', 'title' => 'Geri dönen postalar', 'description' => '550, 554 ve diğer teslim hataları.'],
                        ],
                    ],
                    [
                        'code' => 'mail-sunucu',
                        'slug' => 'sunucu-ve-istemci',
                        'title' => 'Sunucu ve istemci',
                        'description' => 'SMTP, IMAP ve posta kutusu kurulumu.',
                        'boards' => [
                            ['code' => 'mail-smtp', 'slug' => 'smtp-imap', 'title' => 'SMTP ve IMAP', 'description' => 'Bağlantı, port, STARTTLS ve giriş hataları.'],
                            ['code' => 'mail-client', 'slug' => 'posta-istemcileri', 'title' => 'Posta istemcileri', 'description' => 'Outlook, Thunderbird ve telefon kurulumları.'],
                            ['code' => 'mail-hosting', 'slug' => 'yonlendirme', 'title' => 'Yönlendirme ve hosting', 'description' => 'MX, catch-all ve barındırıcı ayarları.'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'dns',
                'slug' => 'dns',
                'title' => 'DNS',
                'description' => 'Kayıtlar, yayılım ve alan adı.',
                'categories' => [
                    [
                        'code' => 'dns-kayit',
                        'slug' => 'dns-kayitlari',
                        'title' => 'Kayıtlar',
                        'description' => 'A, MX, TXT, CNAME ve NS.',
                        'boards' => [
                            ['code' => 'dns-records', 'slug' => 'kayit-sorgulari', 'title' => 'Kayıt sorguları', 'description' => 'Sorgu sonuçları ve yanlış kayıtlar.'],
                            ['code' => 'dns-propagation', 'slug' => 'yayilim', 'title' => 'Yayılım', 'description' => 'TTL ve dünya genelinde güncellenmeyen kayıtlar.'],
                        ],
                    ],
                    [
                        'code' => 'dns-domain',
                        'slug' => 'alan-adi',
                        'title' => 'Alan adı',
                        'description' => 'Whois, süre ve DNSSEC.',
                        'boards' => [
                            ['code' => 'dns-whois', 'slug' => 'whois', 'title' => 'Whois ve süre', 'description' => 'Kayıt tarihi, bitiş ve registrar.'],
                            ['code' => 'dns-dnssec', 'slug' => 'dnssec', 'title' => 'DNSSEC', 'description' => 'İmza ve doğrulama sorunları.'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'topluluk',
                'slug' => 'topluluk',
                'title' => 'Topluluk',
                'description' => 'Duyuru, tanışma ve araç paylaşımları.',
                'categories' => [
                    [
                        'code' => 'topluluk-genel',
                        'slug' => 'genel',
                        'title' => 'Genel',
                        'description' => 'Foruma dair her şey.',
                        'boards' => [
                            ['code' => 'topluluk-duyuru', 'slug' => 'duyurular', 'title' => 'Duyurular', 'description' => 'Site ve forum duyuruları.'],
                            ['code' => 'topluluk-tanisma', 'slug' => 'tanisma', 'title' => 'Tanışma', 'description' => 'Kendinizi kısaca yazın.'],
                            ['code' => 'topluluk-arac', 'slug' => 'arac-sonuclari', 'title' => 'Araç sonuçları', 'description' => 'Sorgu sonuçlarını buraya taşıyın.'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
