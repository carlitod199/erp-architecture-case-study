# Traceability with standardised barcode identifiers

## The requirement

A box of fresh produce leaving a packing house has to carry, on a printed label,
enough information for a buyer thousands of kilometres away to identify what is
inside, how much it weighs, and — critically — which batch it came from. If a
problem is found downstream, the batch identifier is what turns "recall
everything" into "recall these pallets".

That is not a formatting requirement. It is the requirement that the physical
label and the database row cannot drift apart.

## Why a standard rather than an internal code

An internal sequence number would be easier. It would also be useless the moment
the box leaves the building, because the scanner on the other end belongs to
somebody else.

The relevant standard family (GS1) defines an *element string*: a concatenation
of Application Identifiers, each a two-to-four digit prefix declaring what the
following field means. Three of them carry a produce carton:

| AI | Meaning | Format |
|---|---|---|
| `01` | Global trade item number | exactly 14 digits |
| `3103` | Net weight in kilograms | 6 digits, 3 implied decimals |
| `10` | Batch or lot | variable length, up to 20 characters |

Encoded as `(01)10614141000415(3103)001250(10)L2609A`, and printed as a Code 128
barcode with the FNC1 start character that tells a scanner the payload is
GS1-structured.

## The details that decide whether it scans

**Variable-length fields go last.** `AI 10` has no fixed length, so a scanner
cannot know where it ends. Placing it last means it runs to the end of the
payload and no separator character is needed. Put it in the middle and every
field after it needs an FNC1 separator — which works, and which is one more
thing to get wrong. Ordering the fields so the problem cannot arise is cheaper
than handling it.

**Fixed-length fields are padded, not formatted.** A 1.25 kg carton is
`001250` under AI 3103, not `1.25` and not `1250`. The decimal point is implied
by the AI itself. Weight is converted to an integer number of grams and
zero-padded; anything that does not fit the six digits is rejected rather than
truncated, because a truncated weight is a wrong weight that scans perfectly.

**The check digit is verified before printing, not after.** A trade item number
carries a modulo-10 check digit. Shorter forms are left-padded to 14 digits, and
the check digit is recomputed and compared. An invalid number is refused at label
generation. The alternative is discovering it at a customer's receiving dock,
which costs a shipment.

**The batch is sanitised to the permitted character set.** Human-entered batch
codes arrive with accents, slashes, and trailing spaces. They are filtered to the
safe subset and truncated to the field limit before encoding, so what is in the
barcode and what is in the column are the same string.

**Human-readable text accompanies the bars.** The same content in parentheses
notation under the barcode. Scanners fail — a torn label, a wet box, a dead
battery — and when they do, someone types it in. A barcode with no readable text
is a single point of failure for the shipment.

## Rendering

Barcodes and QR codes are rendered as **inline SVG, generated on the server, with
no network call**. Three reasons, in order of importance:

1. **A remote barcode service means an identifier leaves the building** — and a
   batch code plus a trade item number is commercially sensitive.
2. **A packing house does not stop when the internet does.** A label generator
   with a network dependency has an outage mode that halts a shipping line.
3. **SVG scales without resampling.** The same markup prints on a thermal label
   printer and a laser printer. A raster barcode that is resampled by a browser
   is a barcode that intermittently does not scan, which is the worst failure
   mode available: it works in testing.

Bar height carries no information, so the SVG is emitted with a `viewBox` and
sized in millimetres by CSS at the point of use.

QR codes are used for a different job — worker badges — at error-correction
level M. Higher correction survives more dirt but produces a denser symbol at the
same physical size; level M is the compromise that survives a dusty packing house
without needing a larger label.

## Where the identifiers connect

The label is one end of a chain the database has to hold up:

```
block / plot ──> harvest event ──> load / delivery note
                                        │
                                        ▼
                              packing house reception
                                        │
                                        ▼
                             carton ──> batch ──> label
```

The batch on the printed label resolves back through packing, reception, load,
and harvest to a block and a date. Every link is an ordinary foreign key. The
barcode standard governs the label; the database governs the chain; the only
clever part is refusing to let them disagree.

## The trade-off

Implementing a subset of a large commercial standard by hand means what is
implemented is understood, has no dependency that can vanish, and works offline —
and that anything outside the subset is unimplemented. Adding a new Application
Identifier is a code change, not a configuration change. For a fixed set of
carton types that is the right side of the trade; for a business that needed
arbitrary AI combinations it would be the wrong one, and the correct answer would
be a maintained library.
