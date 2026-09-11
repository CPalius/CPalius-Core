<?php

declare(strict_types=1);

namespace Modules\Blog\Command;

use App\Core\Localization\LocaleProvider;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\PostSubType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds one demo category and four long posts of different sub-types (idempotent by slug).
 */
#[AsCommand(
    name: 'cp:blog:seed-demo-content',
    description: 'Creates a sample blog category and long demo posts of different types.',
)]
final class SeedDemoContentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
        private readonly NodeRepository $nodes,
        private readonly UserRepository $users,
        private readonly LocaleProvider $localeProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('author-email', null, InputOption::VALUE_REQUIRED, 'Author email', 'sys@rootali.net')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update content when the same slug already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getOption('author-email');
        $update = (bool) $input->getOption('update');

        $author = $this->users->findOneBy(['email' => $email]);
        if ($author === null) {
            $author = $this->users->findOneBy([]);
        }
        if (!$author instanceof User) {
            $io->error('Author user was not found.');

            return Command::FAILURE;
        }

        $newCategory = $this->ensureCategory(
            'Geliştirici Araçları',
            'gelistirici-araclari',
            'Geliştirme ortamı, CLI yardımcıları, kalite araçları ve günlük iş akışını hızlandıran pratikler.'
        );

        $ai = $this->requireCategory('yapay-zeka');
        $cms = $this->requireCategory('cms-cmf');
        $sys = $this->requireCategory('sysadmin-notlari');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->definitions($newCategory, $ai, $cms, $sys) as $def) {
            $existing = $this->nodes->findOneBy(['slug' => $def['slug'], 'locale' => $this->localeProvider->getDefaultCode()]);
            if ($existing !== null && !$update) {
                $io->writeln(sprintf('  · skipped (exists): %s', $def['slug']));
                ++$skipped;
                continue;
            }

            $node = $existing ?? new Node($def['title'], $def['slug'], 'post', $this->localeProvider->getDefaultCode());
            if ($existing === null) {
                $node->setAuthor($author);
            } else {
                $node->setTitle($def['title']);
            }
            $node->setCategory($def['category']);
            $node->addCategory($def['category']);
            $node->setData([
                'excerpt' => $def['excerpt'],
                'body' => $def['body'],
                'is_featured' => 1,
                'featured_image_asset_id' => $def['imageAssetId'],
                'post_sub_type' => $def['subType'],
                'type_fields' => $def['typeFields'],
                'seo' => [
                    'meta_description' => $def['excerpt'],
                    'focus_keyword' => $def['keyword'],
                    'og_image_asset_id' => null,
                    'canonical_url' => null,
                    'schema_type' => 'BlogPosting',
                    'noindex' => false,
                ],
            ]);
            if ($existing === null) {
                $node->publish();
                $this->em->persist($node);
                ++$created;
                $io->writeln(sprintf('  + created: [%s] %s', $def['subType'], $def['title']));
            } else {
                if ($node->getStatus() !== Node::STATUS_PUBLISHED) {
                    $node->publish();
                }
                ++$updated;
                $io->writeln(sprintf('  ~ updated: [%s] %s', $def['subType'], $def['title']));
            }
        }

        $this->em->flush();
        $io->success(sprintf(
            'Done: %d added, %d updated, %d skipped. New category: %s',
            $created,
            $updated,
            $skipped,
            $newCategory->getName()
        ));

        return Command::SUCCESS;
    }

    private function ensureCategory(string $name, string $slug, string $description): Term
    {
        $existing = $this->categories->findOneBySlug($slug, $this->localeProvider->getDefaultCode());
        if ($existing !== null) {
            return $existing;
        }

        $category = new Term($this->categories->vocabulary(), $name, $slug, $this->localeProvider->getDefaultCode());
        $category->setDescription($description);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function requireCategory(string $slug): Term
    {
        $category = $this->categories->findOneBySlug($slug, $this->localeProvider->getDefaultCode());
        if ($category === null) {
            throw new \RuntimeException(sprintf('Category not found: %s', $slug));
        }

        return $category;
    }

    /**
     * @return list<array{
     *     title: string,
     *     slug: string,
     *     excerpt: string,
     *     keyword: string,
     *     subType: string,
     *     imageAssetId: int,
     *     typeFields: array<string, string>,
     *     category: Term,
     *     body: string
     * }>
     */
    private function definitions(Term $tools, Term $ai, Term $cms, Term $sys): array
    {
        return [
            [
                'title' => 'Üretken Yapay Zeka ile Editöryel İş Akışını Yeniden Tasarlamak',
                'slug' => 'uretken-yapay-zeka-ile-editoryel-is-akisi',
                'excerpt' => 'İçerik ekiplerinin üretken yapay zekayı taslak, özet, SEO ve denetim adımlarına nasıl güvenli biçimde yerleştirebileceğini anlatan kapsamlı bir rehber.',
                'keyword' => 'üretken yapay zeka editöryel',
                'subType' => PostSubType::ARTICLE,
                'imageAssetId' => 5,
                'typeFields' => [],
                'category' => $ai,
                'body' => $this->bodyArticleAi(),
            ],
            [
                'title' => 'CPalius Portal İskeleti: Modüler CMS’den Canlı Bir Ön Yüze',
                'slug' => 'cpalius-portal-iskeleti-moduler-cms',
                'excerpt' => 'CPalius CMF üzerinde portal blokları, tema katmanı ve blog/forum modüllerini birleştiren örnek bir açık kaynak iskeletin mimari notları.',
                'keyword' => 'cpalius portal iskeleti',
                'subType' => PostSubType::PROJECT,
                'imageAssetId' => 3,
                'typeFields' => [
                    'repo_url' => 'https://github.com/cpalius/portal-skeleton',
                    'demo_url' => 'https://demo.cpalius.dev/portal',
                ],
                'category' => $cms,
                'body' => $this->bodyProjectCms(),
            ],
            [
                'title' => 'cp-watchdog 1.4: Hafif Sunucu Sağlık Denetimi',
                'slug' => 'cp-watchdog-hafif-sunucu-saglik-denetimi',
                'excerpt' => 'Disk, bellek, kuyruk ve HTTP uçlarını tek bir CLI ile tarayan cp-watchdog aracının tasarım kararları, kurulum adımları ve operasyon ipuçları.',
                'keyword' => 'cp-watchdog sunucu izleme',
                'subType' => PostSubType::SOFTWARE,
                'imageAssetId' => 2,
                'typeFields' => [
                    'version' => '1.4.2',
                    'download_url' => 'https://downloads.cpalius.dev/cp-watchdog-1.4.2.phar',
                ],
                'category' => $sys,
                'body' => $this->bodySoftwareSysadmin(),
            ],
            [
                'title' => 'Doctrine QueryBuilder’da N+1’i Erken Yakalamak',
                'slug' => 'doctrine-querybuilder-n1-erken-yakalamak',
                'excerpt' => 'Liste sayfalarında sessizce büyüyen N+1 sorgularını nasıl görünür kılacağınızı, join stratejilerini ve güvenli sayfalama notlarını içeren geliştirici notu.',
                'keyword' => 'doctrine n+1 querybuilder',
                'subType' => PostSubType::NOTE,
                'imageAssetId' => 4,
                'typeFields' => [
                    'code_snippet' => $this->noteCodeSnippet(),
                ],
                'category' => $tools,
                'body' => $this->bodyNoteTools(),
            ],
        ];
    }

    private function bodyArticleAi(): string
    {
        return <<<'HTML'
<p>Üretken yapay zeka, içerik ekiplerinin hızını artırırken aynı zamanda editöryel standartları zorlayan yeni bir katman oluşturdu. Taslak üretmek artık dakikalar sürüyor; asıl mesele hız değil, güven, tutarlılık ve marka sesinin korunması. Bu yazıda üretken modelleri bir “otomatik yazar” gibi değil, denetimli bir asistan gibi konumlandıran bir iş akışı öneriyoruz. Amaç, üretim kapasitesini büyütürken geri çekilen yayın oranını ve hukuki riski düşürmektir.</p>
<p>İlk adım kapsamı netleştirmektir. Hangi içerik türlerinde yapay zeka kullanılacak? Haber özeti, ürün güncellemesi, teknik rehber ve görüş yazısı aynı risk profiline sahip değildir. Teknik rehberlerde doğruluk kritikken, görüş yazılarında özgün ses ve deneyim öne çıkar. Bu yüzden her tür için ayrı bir kabul kriteri listesi çıkarın: zorunlu kaynaklar, yasaklı iddialar, stil kılavuzu ve insan onayı gerektiren eşikler. Kapsam net değilse ekip her metinde yeniden tartışır ve hız kazancı buharlaşır.</p>
<p>İkinci adım girdi kalitesidir. Model çıktısı, brief’in netliği kadar iyidir. İyi bir brief hedef okuyucuyu, temel mesajı, kaçınılacak iddiaları, istenen uzunluğu ve referans linklerini içerir. “SEO için yaz” gibi belirsiz talimatlar yerine “ilk iki paragrafta sorunu tanımla, üçüncü paragrafta ölçülebilir faydayı anlat, sonuçta tek bir eylem çağrısı bırak” gibi yapısal kısıtlar verin. Yapı, hem okunabilirliği hem de sonraki denetimi kolaylaştırır. Brief şablonlarını CMS içinde alan olarak tutmak, tekrarlayan işi azaltır.</p>
<p>Üçüncü adım üretim sonrası denetimdir. Editör yalnızca yazım hatalarına bakmamalı; olgusal doğrulama, kaynak kontrolü, hukuki risk taraması ve marka sesi kontrolü ayrı checklist’ler halinde ilerlemelidir. Özellikle istatistik, fiyat, sürüm numarası ve üçüncü taraf ürün iddiaları insan doğrulaması olmadan yayınlanmamalıdır. Bu noktada CMS içindeki taslak → inceleme → yayın durumları, yapay zeka çıktısını görünür bir boru hattına bağlamanızı sağlar. Denetim adımları atlanırsa hız, pahalı bir yanlış güven duygusuna dönüşür.</p>
<p>Dördüncü adım yeniden kullanım ve bilgi tabanıdır. Üretilen her kabul edilmiş metinden kısa özetler, SSS parçaları ve dahili notlar türetilebilir. Ancak türetme işlemi de aynı denetim kapısından geçmelidir. Aksi halde hatalı bir iddia onlarca sayfaya çoğalır. Bilgi tabanınızı “modelin hafızası” gibi değil, versiyonlu ve sahipli bir kurumsal varlık gibi yönetin. Kim ne zaman onayladı sorusunun cevabı her zaman bulunabilmelidir.</p>
<p>SEO tarafında üretken içerik iki ucu keskin bir bıçaktır. Arama motorları faydalı, özgün ve deneyime dayanan içeriği ödüllendirmeye devam ediyor. Bu yüzden model çıktısını ham haliyle yayınlamak yerine, yazarın sahadan getirdiği gözlemlerle zenginleştirin. Ekran görüntüleri, ölçüm sonuçları, hata günlükleri ve gerçek kullanıcı senaryoları metni rakiplerden ayırır. Anahtar kelime yerleştirme otomatikleştirilebilir; uzmanlık ve güven inşa edilemez. Uzun vadede ince ama dürüst içerik, şişkin ama boş içerikten daha sürdürülebilirdir.</p>
<p>Operasyonel olarak ekip rollerini yeniden tanımlayın. “Yazar” rolü tamamen kaybolmaz; “kıdemli editör / doğrulayıcı” ve “brief mimarı” rolleri güçlenir. Junior ekip üyeleri brief hazırlama ve kaynak toplama konusunda ustalaşırken, kıdemli isimler doğruluk ve anlatı kalitesinden sorumlu olur. Performans metriklerini de yalnızca kelime sayısına değil, düzeltme turu sayısına, geri çekilen yayın oranına ve okur etkileşimine bağlayın. Böylece teşvikler kaliteyle hizalanır.</p>
<p>Güvenlik ve gizlilik ayrı bir başlıktır. Müşteri verisi, sözleşme maddeleri, yayınlanmamış yol haritası veya kişisel bilgiler prompt’a asla girilmemelidir. Kurumsal bir model geçidi ile hangi araçların hangi veriyi görebileceğini sınırlandırın. Loglarda prompt saklıyorsanız bunları da kişisel veri gibi sınıflandırın. Yerel veya özel barındırılan modeller, hassas içerik için daha uygun olabilir; genel amaçlı bulut modelleri ise genel bilgilendirme metinlerinde hız kazandırır. Politika yazılı değilse uygulama rastlantısaldır.</p>
<p>Eğitim de sürecin parçasıdır. Ekibe “prompt yazma” değil, “iyi brief yazma ve kötü çıktıyı reddetme” öğretin. Örnekleri paylaşın: kabul edilen ve reddedilen metinleri yan yana koyup nedenlerini tartışın. Bu retrospektifler, stil kılavuzunu canlı tutar. Üç ayda bir araçları değil, karar kalitesini gözden geçirin.</p>
<p>Son olarak ölçümleyin. Yapay zeka destekli iş akışının gerçekten zaman kazandırıp kazandırmadığını, kaliteyi düşürüp düşürmediğini ve arama performansını nasıl etkilediğini üç aylık dönemlerde değerlendirin. Kazanç yoksa araç değil süreç bozuktur. Kazanç varsa ölçekleyin; ancak ölçekleme, denetim kapasitesini de aynı oranda artırmadan yapılmamalıdır. Üretken yapay zeka bir kısayol değil, editöryel sistemin yeni bir bileşenidir; doğru bağlandığında hız ve kaliteyi birlikte yükseltir.</p>
HTML;
    }

    private function bodyProjectCms(): string
    {
        return <<<'HTML'
<p>CPalius Portal İskeleti, CPalius CMF’nin modüler yapısını gerçek bir ön yüz deneyimine dönüştürmek için tasarlanmış bir referans projedir. Amaç “her şeyi içeren monolit bir tema” üretmek değil; blog, forum ve portal bloklarını aynı istek yaşam döngüsünde tutarlı biçimde sunan ince bir kompozisyon katmanı oluşturmaktır. Bu yazıda projenin mimari kararlarını, dizin düzenini ve günlük geliştirme ritmini anlatıyoruz. Okuyucunun projeyi çatallayıp ilk günde anlamlı bir ekran görmesi temel başarı ölçütüdür.</p>
<p>İskeletin merkezinde tema katmanı vardır. Tema, HTML iskeletini, tipografi ve boşluk sistemini, navigasyonu ve paylaşılan kısmi şablonları taşır. Modüller ise kendi domain’lerine ait şablonları tema ad alanına override ederek sunar. Böylece blog arşivi temanın dilini konuşurken, forum konu listesi de aynı breadcrumb ve sidebar dilini paylaşır. Bu yaklaşım, her modülün kendi CSS evrenini yaratmasını engeller ve marka tutarlılığını korur. Görsel borç, çoğu zaman modül başına özel “mini tema” üretmekten doğar.</p>
<p>Portal blokları ikinci kritik parçadır. Ana sayfa bir “tek şablon” olmaktan çıkar; yapılandırılabilir blok sıralamasına dönüşür. Her blok bir veri sağlayıcıya bağlanır: son yazılar, popüler konular, forum panoları veya özel HTML. Blokların görünürlüğü, limiti ve yerleşimi Studio üzerinden yönetilir. Bu sayede pazarlama ekibi kod deploy etmeden ana sayfa kompozisyonunu değiştirebilir; geliştirici ise yalnızca yeni blok tipleri ekler. Yapılandırma JSON’u versiyonlanabilir ve ortamlar arasında taşınabilir tutulmalıdır.</p>
<p>Veri erişiminde modül servisleri sınırdır. Temanın doğrudan repository çağırması bilinçli olarak tercih edilmez. Bunun yerine blog görünüm servisi, forum istatistik servisi ve portal blok sağlayıcıları ortak bir sözleşme üzerinden çalışır. Bu ayrım, N+1 sorgularını erken yakalamayı ve önbellek stratejisini tek yerde toplamayı kolaylaştırır. Liste sayfalarında eager join, detay sayfalarında ise ihtiyaç duyulan ilişkilerin seçilmesi temel kuraldır. Servis katmanı olmadan tema şişer ve test edilemez hale gelir.</p>
<p>Routing tarafında her modül kendi ön yüz rotalarını getirir. Tema yalnızca ortak layout’u sağlar. Bu, “tema büyüdükçe her şeyi bilen bir tanrı nesnesine dönüşmesin” ilkesinin pratik karşılığıdır. Yeni bir içerik tipi eklendiğinde tema dosyalarına yüzlerce satır eklemek yerine, ilgili modülün Twig ad alanı genişletilir. Gerekirse tema override ile görsel ince ayar yapılır. Rota isimleri tutarlı tutulursa menü yönetimi de sadeleşir.</p>
<p>Projeyi yerel ortamda ayağa kaldırmak için Laragon veya Docker yeterlidir. Ortam değişkenleri, varlık derlemesi ve medya dizini izinleri README’de adım adım yer alır. Geliştirici deneyimini hızlandırmak için örnek içerik seed komutları ve temel duman testleri eklenmiştir. Amaç, katkı veren birinin ilk günde hem admin hem ön yüz akışını görmesidir. “Çalıştırılamayan demo”, katkı bariyeridir.</p>
<p>Stil sisteminde aşırı soyutlama bilinçli olarak sınırlandırılmıştır. Bir avuç CSS değişkeni, tutarlı spacing ölçeği ve modül önekli BEM sınıfları yeterlidir. Portal slider, blog hero ve kategori haritası gibi parçalar aynı navigasyon yüksekliği ve tipografi ritmini paylaşır. Böylece yeni bir bileşen eklendiğinde tasarım sistemi yeniden icat edilmez; mevcut dil genişletilir. İstisnalar belgelenir, sessizce çoğalmaz.</p>
<p>Güvenlik açısından iskelet, yetenek tabanlı erişim kontrolünü varsayar. Studio ve AACP panelleri ayrı panellerdir; içerik düzenleme ile sistem yönetimi karışmaz. CSRF, XSS ve yükleme güvenliği çekirdek katmanda ele alınır. Tema yalnızca güvenli kaçışlı Twig çıktısı üretir; ham HTML yalnızca bilinçli olarak işaretlenmiş alanlarda kullanılır. Medya seçici ve yükleme akışları da aynı güvenlik varsayımlarını paylaşır.</p>
<p>Gözlemlenebilirlik de referans projenin parçasıdır. Sorgu sayacı, yavaş sayfa logları ve temel uptime kontrolleri örnek olarak bağlanmıştır. Böylece performans regresyonu “hissetmek” yerine ölçülür. Katkı modeli küçük PR’ları, net kabul kriterlerini ve görsel regresyon notlarını bekler. “Çalışıyor” yeterli değildir; erişilebilirlik, mobil kırılım ve sorgu sayısı da kontrol listesindedir.</p>
<p>CPalius Portal İskeleti’nin başarısı, tek bir demoyu şık göstermek değil; ekiplerin kendi markalarını aynı omurga üzerinde güvenle büyütmesidir. Modülerlik slogan değil, günlük geliştirme hızının kaynağı olmalıdır. Bu iskelet o hedefe giden pratik bir başlangıç noktasıdır.</p>
HTML;
    }

    private function bodySoftwareSysadmin(): string
    {
        return <<<'HTML'
<p>cp-watchdog, küçük ve orta ölçekli Laravel/Symfony kurulumlarında “sabah ilk iş sunucu iyi mi?” sorusuna hızlı cevap veren hafif bir sağlık denetim aracıdır. Prometheus kadar kapsamlı olmayı hedeflemez; disk doluluğu, bellek baskısı, kuyruk birikmesi, TLS sertifika süresi ve kritik HTTP uçlarının yanıt kodunu tek bir CLI çağrısında toplar. Bu yazıda 1.4 serisinin tasarım kararlarını ve üretimde kullanım biçimini paylaşıyoruz. Araç, özellikle tek kişilik veya küçük operasyon ekiplerinin “gözlem yığını kurmadan önce” ihtiyaç duyduğu boşluğu doldurur.</p>
<p>Aracın temel felsefesi şudur: operatörün dikkatini dağıtmadan sinyal üret. Her kontrol bir eşik, bir önem derecesi ve bir düz metin mesajı döndürür. Çıktı hem insan okumasına hem de JSON’a uygundur. Cron ile çalıştırıldığında yalnızca uyarı ve kritik durumlar sohbet kanalına iletilebilir; başarılı koşular sessiz kalır. Bu sayede gürültü azalır ve gerçek sorunlar görünür kalır. Sessizlik bir özelliktir; sürekli yeşil mesajlar uyarı yorgunluğu yaratır.</p>
<p>Kurulum bilerek basittir. Tek bir PHAR dosyası veya Composer paketi olarak dağıtılabilir. Yapılandırma YAML dosyasında tutulur: izlenecek yollar, yüzde eşikleri, HTTP uçları, zaman aşımları ve bildirim hedefi. Ortam değişkenleriyle sırlar enjekte edilir; yapılandırma dosyasına token yazılmaz. Root yetkisi gerektirmez; okuma izni olan metriklerle yetinir. Dakikalar içinde ilk anlamlı raporu alabilmek tasarımın parçasıdır.</p>
<p>Disk denetimi yalnızca yüzdeye bakmaz. Inode tükenmesi, yavaş büyüyen log dizinleri ve beklenmedik temp şişmeleri için ayrı kurallar tanımlanabilir. Bellek tarafında available memory ve swap baskısı birlikte değerlendirilir. “RAM yüzde 90” tek başına panik sebebi olmayabilir; swap thrashing ise gecikmeli ama pahalı bir işarettir. cp-watchdog bu ayrımı mesajlarında açıkça belirtir. Operatör, semptomu değil kök sinyali görmelidir.</p>
<p>Uygulama katmanı kontrolleri kuyruk derinliği, başarısız job sayısı ve son başarılı cron zaman damgasını kapsar. Symfony Messenger veya benzeri bir taşıyıcı kullanıyorsanız, stalled worker’ları erken görmek kesinti süresini ciddi biçimde kısaltır. HTTP kontrolleri ise sağlık uçlarını, admin giriş sayfasını veya statik bir varlık URL’sini periyodik olarak yoklar. 5xx ve beklenmeyen yönlendirmeler uyarı üretir. Yanlışlıkla kapanmış bir bakım sayfası bile yakalanabilir.</p>
<p>1.4 sürümünde eklenen önemli özelliklerden biri “sakin dönem” desteğidir. Gece bakım pencerelerinde düşük öncelikli uyarılar bastırılabilir; kritik uyarılar her zaman geçer. Ayrıca TLS sertifika bitiş kontrolü gün cinsinden eşik alır ve yenileme otomasyonu unutulduğunda haftalar öncesinden hatırlatır. Sertifika yenileme, hâlâ en sık unutulan ve en pahalı ihmalden biridir.</p>
<p>Bildirim katmanı e-posta, webhook ve basit bir stdout kancası sunar. Webhook gövdesi sabit bir şemaya sahiptir; böylece ChatOps botları kolayca ayrıştırabilir. Yanlış pozitifleri azaltmak için ardışık başarısızlık sayacı vardır: tek seferlik ağ takılması hemen alarm üretmez, eşik aşıldığında üretir. Bu, gece yarısı gereksiz uyanmaları azaltır.</p>
<p>Güvenlik notu: cp-watchdog sisteme yazmaz, yalnızca okur ve raporlar. Yine de yapılandırma dosyasındaki URL’ler ve eşikler yetkisiz kişilerin eline geçmemelidir. PHAR bütünlüğü için yayınlanan checksum doğrulanmalıdır. Güncellemeler semver ile ilerler; kırıcı değişiklikler majör sürümde duyurulur. Bağımlılık yüzeyi bilerek dar tutulur.</p>
<p>Kapasite planlama açısından araç tarihsel trend tutmaz; o işi zaman serisi sistemlerine bırakır. Buna karşılık “şu anki eşik aşıldı mı?” sorusunu çok ucuza cevaplar. Birçok ekip önce cp-watchdog ile görünürlük kazanır, sonra ihtiyaç olgunlaştıkça Prometheus veya benzeri bir yığına geçer. İkisi birbirinin rakibi değil, tamamlayıcısıdır.</p>
<p>Özetle cp-watchdog, büyük gözlem yığını kurmadan önce ihtiyaç duyulan pratik bir güvenlik ağıdır. Gözlem stack’iniz olgunlaştığında bile, bağımsız bir dış denetim olarak kalmaya devam edebilir. Çünkü bazen asıl sorun, izleme sisteminin kendisinin sessizce çökmesidir; ikinci bir göz her zaman değerlidir.</p>
HTML;
    }

    private function bodyNoteTools(): string
    {
        return <<<'HTML'
<p>Liste sayfalarında performans sorunu çoğu zaman “yavaş sunucu” değildir; aynı isteğin içinde onlarca gizli sorgu çalışmasıdır. Doctrine’da N+1, ilişkili varlıklar döngü içinde tembel yüklendiğinde ortaya çıkar. Bu not, CPalius benzeri içerik listelerinde sorunu erken yakalamak için kullandığımız pratikleri özetler. Kısa tutulmuş gibi görünse de buradaki alışkanlıklar, aylar sonra ortaya çıkan gizemli gecikmeleri engeller.</p>
<p>İlk kural: geliştirme ortamında sorgu sayısını görünür kılın. Bir middleware veya toolbar ile SELECT sayısını ve etkilenen tabloları loglamak, regresyonu PR aşamasında yakalar. “Bu sayfa 8 SELECT’i geçmesin” gibi basit bir bütçe, ekibi bilinçli join kullanmaya iter. Üretimde aynı bütçeyi sert hata yapmak yerine uyarı olarak tutmak daha güvenlidir. Görünmeyen maliyet, yönetilemeyen maliyettir.</p>
<p>İkinci kural: liste sorgusunda gerçekten ihtiyaç duyulan ilişkileri baştan yükleyin. Yazar adı, birincil kategori ve kapak alanları kartta görünüyorsa, bu ilişkiler döngüden önce join/select edilmelidir. Ancak her şeyi join etmek de yanlıştır; gereksiz cartesian çarpım paginator’ı bozar. Many-to-many ilişkilerde Paginator kullanırken fetchJoinCollection davranışını bilinçli seçin. “Hepsini join ettim, artık hızlı” çoğu zaman yanlış bir güvendir.</p>
<p>Üçüncü kural: sayım ve listeyi ayırın. COUNT sorgusu ile satır çeken sorgu aynı DQL’i körü körüne paylaşmak zorunda değildir. Sıralama ifadeleri, gereksiz join’ler ve select alanları sayım maliyetini şişirir. Ayrı bir sayım sorgusu bazen daha net ve daha hızlıdır. Özellikle filtre sayısı arttıkça bu ayrımın değeri yükselir.</p>
<p>Dördüncü kural: JSON veri alanlarına güvenip ilişki gibi davranmayın. Node::data içindeki anahtarlar esneklik sağlar ama indeks ve join ihtiyacını ortadan kaldırmaz. Sık filtrelenen alanlar örneğin post_sub_type alan indeksine yazılmalıdır. Aksi halde her liste “tüm satırları çek, PHP’de ele” anti-pattern’ine kayar. Esneklik ile sorgulanabilirlik arasında bilinçli bir denge kurun.</p>
<p>Beşinci kural: Twig tarafında gizli N+1’i unutmayın. Şablon içinde post.author.profile veya category.children gezmek, controller’da sorunsuz görünen bir sorguyu patlatabilir. Kısmi şablonlara veri bilerek enjekte edin; şablonun entity grafiğinde serbest dolaşmasına izin vermeyin. Sunum katmanı, veri erişim stratejisini sessizce sabote edebilir.</p>
<p>Altıncı kural: ölçmeden optimize etmeyin. EXPLAIN planı, yavaş sorgu logu ve gerçek sayfa zamanlaması olmadan “şu join’i ekleyelim” demek yanlış indekslere yol açabilir. Özellikle locale + status + published_at kombinasyonları için bileşik indeksler, blog arşivlerinde sık kazanç sağlar. Tahmine dayalı mikro optimizasyonlar bakım maliyetini artırır.</p>
<p>Yedinci kural: testlere bir “sorgu bütçesi” iddiası ekleyin. Fonksiyonel testte sayfa 200 dönse bile SELECT sayısı eşik aşıyorsa test kırılmalıdır. Bu, refactor sırasında N+1’in geri gelmesini engeller. Küçük bir assertion, büyük bir operasyonel tasarruftur.</p>
<p>Sekizinci kural: önbelleği N+1’in üstüne bandaj gibi yapıştırmayın. Yanlış sorguları önbelleklemek, yanlış sonucu daha hızlı sunar. Önce sorguyu düzeltin, sonra gerçekten pahalı ve stabil sonuçları önbellekleyin. Önbellek anahtarları locale, filtre ve kullanıcı yetkisine duyarlı olmalıdır.</p>
<p>Son olarak ekip alışkanlığına dönüştürün. Yeni bir liste endpoint’i açıldığında kontrol listesine sorgu sayısı ekran görüntüsü ekleyin. Küçük bir disiplin, ilerideki performans yangınlarını azaltır. Code review’da “bu döngüde ilişki geziliyor mu?” sorusunu standart hale getirin; çoğu N+1 tam olarak orada doğar. Release notlarına da kısa bir performans bölümü eklemek, gerilemeyi görünür kılar. Şüpheli bir sayfada önce toolbar’a bakın, sonra tahmine yönelin. Aşağıdaki örnek, yazar ilişkisini baştan yükleyen minimal bir QueryBuilder desenidir; kendi listenize göre alanları daraltın, sayfa bütçesini ölçerek doğrulayın ve eşik aşıldığında alarmı görünür bırakın. Performans, bir kerelik kahramanlık değil; tekrarlanan mühendislik hijyenidir.</p>
HTML;
    }

    private function noteCodeSnippet(): string
    {
        return <<<'PHP'
$qb = $em->createQueryBuilder()
    ->select('n', 'a', 'c')
    ->from(Node::class, 'n')
    ->leftJoin('n.author', 'a')
    ->leftJoin('n.category', 'c')
    ->andWhere('n.type = :type')
    ->andWhere('n.locale = :locale')
    ->andWhere('n.status = :status')
    ->andWhere('n.deletedAt IS NULL')
    ->setParameter('type', 'post')
    ->setParameter('locale', 'tr')
    ->setParameter('status', Node::STATUS_PUBLISHED)
    ->orderBy('n.publishedAt', 'DESC')
    ->setMaxResults(12);

$posts = $qb->getQuery()->getResult();
// Avoid extra SELECTs for post.author / post.category on card templates.
PHP;
    }
}
