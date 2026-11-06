<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\Elot743;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ELOT 743, as Greek passports write names (WG-23). */
class Elot743Test extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names()
    {
        return [
            'θ, σσ, accent' => ['Θεσσαλονίκη', 'Thessaloniki'],
            'capitals stay capitals' => ['ΘΕΣΣΑΛΟΝΙΚΗ', 'THESSALONIKI'],
            'ευ before a vowel, γγ' => ['Ευάγγελος', 'Evangelos'],
            'ευ in capitals' => ['ΕΥΑΓΓΕΛΟΣ', 'EVANGELOS'],
            'αυ before an unvoiced' => ['Αυτοκίνητο', 'Aftokinito'],
            'ευ at the end' => ['Ζευ', 'Zef'],
            'ευ before a voiced' => ['Ευγενία', 'Evgenia'],
            'ηυ' => ['ηύρα', 'ivra'],
            'μπ at the start and inside' => ['Μπάμπης', 'Bampis'],
            'μπ inside' => ['Λαμπράκης', 'Lamprakis'],
            'μπ at the end' => ['λαμπ', 'lab'],
            'ντ' => ['Αντώνης', 'Antonis'],
            'ου' => ['Γεωργίου', 'Georgiou'],
            'χ, ψ' => ['Χαράλαμπος Ψαρράς', 'Charalampos Psarras'],
            'γκ' => ['Αγκάθι', 'Agkathi'],
            'γξ' => ['σφίγξ', 'sfinx'],
            'γχ' => ['Αγχίαλος', 'Anchialos'],
            'ξ, ω, ς' => ['Ξενοφών Παπαδόπουλος', 'Xenofon Papadopoulos'],
            'diaeresis breaks a digraph' => ['Ευρωπαϊκή', 'Evropaiki'],
            'diaeresis on υ' => ['Μωυσής', 'Moysis'],
            'an accent on the first breaks it' => ['άυλος', 'aylos'],
            'an accent on υ keeps it' => ['αύριο', 'avrio'],
            'υ alone' => ['Κύπρος', 'Kypros'],
            'one capital letter' => ['Θ.', 'Th.'],
            'Latin and digits left as they are' => ['Οδός Ερμού 12, Athens', 'Odos Ermou 12, Athens'],
            'company' => ['ΜΑΪ ΙΠ ΝΕΤΓΟΥΟΡΚΣ Ο.Ε.', 'MAI IP NETGOUORKS O.E.'],
            'nothing Greek' => ['John Smith', 'John Smith'],
            // Review of #669.
            'capitals: μπ, ου, γκ, ντ' => ['ΜΠΑΜΠΟΥΡΗΣ ΓΚΑΝΤΑΣ', 'BAMPOURIS GKANTAS'],
            'αυ before θ, φ, χ, ψ, ξ' => ['αυθ αυφ αυχ αυψ αυξ', 'afth aff afch afps afx'],
            'ευ with an accented υ in capitals' => ['ΕΎΚΟΛΟΣ', 'EFKOLOS'],
            'a one-letter capital in a text of capitals' => ['Θ. ΠΑΠΑΔΟΠΟΥΛΟΣ', 'TH. PAPADOPOULOS'],
            'initials' => ['Χ.Θ. Παπάς', 'Ch.Th. Papas'],
            'mixed case inside a word' => ['ΜπΑΜΠΗΣ', 'BAMPIS'],
            'decomposed accent (NFD)' => ["α\u{0301}νθος", 'anthos'],
            'decomposed diaeresis breaks the digraph' => ["αυ\u{0308}λ", 'ayl'],
            'polytonic' => ['ἀρχὴ τοῦ Ἑλληνισμοῦ', 'archi tou Ellinismou'],
            'Greek question mark and ano teleia' => ["Τι;\u{037E} α\u{0387}β", 'Ti;; a;v'],
            'guillemets' => ['«Ελλάς»', '"Ellas"'],
            'symbol forms' => ['ϐϑϕϲµ', 'vthfsm'],
            'invalid UTF-8 dropped, the rest transliterated' => ["\xffΘέση", 'Thesi'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('names')]
    public function test_elot_743($greek, $latin)
    {
        $this->assertSame($latin, Elot743::transliterate($greek));
    }
}
