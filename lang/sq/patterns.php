<?php

/*
 * Albanian for messages that contain names or other free text. The key is a regular expression for the
 * English message; $1, $2… in the translation are its captured parts.
 */

return [
    '/^Line (\d+): (.*)$/s' => 'Rreshti $1: $2',
    '/^The (NUI|code) (.+) is also on line (\d+)\.$/' => 'Vlera $2 gjendet edhe në rreshtin $3.',
    '/^(.+): the (NUI|code) (.+) is already in this business\.$/' => '$1: vlera $3 ekziston tashmë në këtë biznes.',
    '/^The barcode (.+) already belongs to another item\.$/' => 'Barkodi $1 i përket tashmë një artikulli tjetër.',
    '/^The barcode (.+) is also on line (\d+)\.$/' => 'Barkodi $1 gjendet edhe në rreshtin $2.',
    '/^The first row must name the columns\. Missing: (.+)\. Download the template to see the expected columns\.$/' => 'Rreshti i parë duhet t\'i emërtojë kolonat. Mungojnë: $1. Shkarko shabllonin për t\'i parë kolonat e pritura.',
    '/^There is no active item with the code (.+)\.$/' => 'Nuk ka artikull aktiv me kodin $1.',
    '/^This account type cannot be used for (.+)\.$/' => 'Ky lloj llogarie nuk mund të përdoret për: $1.',
    '/^Map active ledger accounts for (.+) before posting automatically\.$/' => 'Cakto llogari kontabël aktive para postimit automatik për: $1.',
    '/^The statement must contain one \'(.+)\' column\.$/' => 'Ekstrakti duhet të ketë një kolonë \'$1\'.',
    '/^This would leave the cash account "(.+)" at (-?[\d.]+) on ([\d-]+)\. Cash on hand cannot be negative, so record the missing cash receipts first\.$/' => 'Kjo do ta linte arkën "$1" me $2 më $3. Paraja në arkë nuk mund të jetë negative, prandaj së pari regjistro arkëtimet që mungojnë.',
    '/^Only ([\d.]+) is open on this party\'s (.+) up to this date, which is less than the bank amount\. Issue or record the missing invoice first, or choose another category\.$/' => 'Deri në këtë datë, kjo palë ka vetëm $1 të hapura, më pak se shuma e bankës. Së pari lësho ose regjistro faturën që mungon, ose zgjidh një kategori tjetër.',
    '/^Reopen the later closed period "(.+)" first; it carries this period\'s balances forward\.$/' => 'Së pari rihap periudhën e mëvonshme të mbyllur "$1"; ajo i bart saldot e kësaj periudhe.',
    '/^Cancelling this invoice would make stock of (.+) negative in (.+)\.$/' => 'Anulimi i kësaj fature do ta bënte negativ stokun e artikullit $1 në depon $2.',
    '/^The NUI of this business \((.*)\) is not 9 digits\. Correct it in the business details; it is printed on every invoice\.$/' => 'NUI i këtij biznesi ($1) nuk ka 9 shifra. Korrigjoje te të dhënat e biznesit; shtypet në çdo faturë.',
    '/^This API key may not do this \(missing ability: (.+)\)\.$/' => 'Ky çelës API nuk e ka këtë të drejtë ($1).',
    '/^Submitted for review: (.+)$/s' => 'Dorëzuar për rishikim: $1',
    '/^Approve reopening (.+)\? Asked by (.+?): (.*)$/s' => 'Të miratohet rihapja e periudhës $1? Kërkuar nga $2: $3',
    '/^No query results for model/' => 'Nuk u gjet.',
    '/^The route .+ could not be found\.$/' => 'Nuk u gjet.',
    '/^Count recorded\./' => 'Numërimi u regjistrua.',
];
