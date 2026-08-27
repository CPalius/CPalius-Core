<?php

namespace App\Core\Security;

/**
 * CPaliusVoter'ın parametrik ".own" yeteneklerini (ör. "node.post.edit.own")
 * çözebilmesi için bir varlığın "sahibi kim" sorusuna cevap verme sözleşmesi.
 * Node gibi içerik taşıyan entity'ler bunu implement ettiğinde, Voter
 * subject'in sahibiyle mevcut kullanıcıyı otomatik olarak karşılaştırabilir.
 */
interface OwnableInterface
{
    /**
     * Sahibi olan kullanıcının id'si, sahipsizse null.
     */
    public function getOwnerId(): ?int;
}
