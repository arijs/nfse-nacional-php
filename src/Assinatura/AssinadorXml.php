<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Assinatura;

use Rhengles\NfseNacional\Certificado\CertificadoDigital;
use Rhengles\NfseNacional\Excecao\AssinaturaExcecao;

/**
 * Assinatura XMLDSig "enveloped" do padrão NFS-e: assina o elemento que tem o
 * atributo `Id` (infDPS, infPedReg) e coloca o `<Signature>` como irmão dele,
 * dentro do elemento raiz — exatamente onde o XSD (TCDPS / TCPedRegEvt) o
 * espera.
 *
 * Detalhes que a Sefin cobra e que são fáceis de errar:
 *  - `<Signature>` SEM prefixo de namespace (xmlns="...xmldsig#" direto);
 *  - dois Transforms, nesta ordem: enveloped-signature e o C14N;
 *  - KeyInfo só com X509Data/X509Certificate (sem SubjectName, IssuerSerial,
 *    KeyValue etc.);
 *  - SignatureValue e DigestValue em Base64 numa linha só.
 */
final class AssinadorXml
{
	public const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
	public const ALGO_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

	public function __construct(
		private readonly CertificadoDigital $certificado,
		private readonly PerfilAssinatura $perfil = PerfilAssinatura::Sha1,
	) {
	}

	/**
	 * @param string $tagAlvo elemento a assinar (infDPS, infPedReg)
	 */
	public function assinar(string $xml, string $tagAlvo = 'infDPS', string $atributoId = 'Id'): string
	{
		$dom = self::carregar($xml);
		$alvo = $dom->getElementsByTagName($tagAlvo)->item(0);
		if (!$alvo instanceof \DOMElement) {
			throw new AssinaturaExcecao("Elemento <{$tagAlvo}> não encontrado no XML.");
		}
		$id = $alvo->getAttribute($atributoId);
		if ('' === $id) {
			throw new AssinaturaExcecao("Elemento <{$tagAlvo}> sem atributo {$atributoId}.");
		}
		$raiz = $alvo->parentNode;
		if (!$raiz instanceof \DOMElement) {
			throw new AssinaturaExcecao("<{$tagAlvo}> precisa estar dentro do elemento raiz do documento.");
		}
		if (0 !== $raiz->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->length) {
			throw new AssinaturaExcecao('O XML já contém uma assinatura.');
		}

		$exclusiva = $this->perfil->c14nExclusiva();
		$digest = base64_encode(hash($this->perfil->hash(), $alvo->C14N($exclusiva, false), true));

		$signature = $dom->createElementNS(self::NS_DSIG, 'Signature');
		$raiz->appendChild($signature);

		$signedInfo = $this->el($dom, 'SignedInfo');
		$signature->appendChild($signedInfo);
		$signedInfo->appendChild($this->el($dom, 'CanonicalizationMethod', null, ['Algorithm' => $this->perfil->algoritmoC14n()]));
		$signedInfo->appendChild($this->el($dom, 'SignatureMethod', null, ['Algorithm' => $this->perfil->algoritmoAssinatura()]));
		$reference = $this->el($dom, 'Reference', null, ['URI' => '#'.$id]);
		$signedInfo->appendChild($reference);
		$transforms = $this->el($dom, 'Transforms');
		$reference->appendChild($transforms);
		$transforms->appendChild($this->el($dom, 'Transform', null, ['Algorithm' => self::ALGO_ENVELOPED]));
		$transforms->appendChild($this->el($dom, 'Transform', null, ['Algorithm' => $this->perfil->algoritmoC14n()]));
		$reference->appendChild($this->el($dom, 'DigestMethod', null, ['Algorithm' => $this->perfil->algoritmoDigest()]));
		$reference->appendChild($this->el($dom, 'DigestValue', $digest));

		$signatureValue = $this->el($dom, 'SignatureValue');
		$signature->appendChild($signatureValue);

		$keyInfo = $this->el($dom, 'KeyInfo');
		$signature->appendChild($keyInfo);
		$x509Data = $this->el($dom, 'X509Data');
		$keyInfo->appendChild($x509Data);
		$x509Data->appendChild($this->el($dom, 'X509Certificate', $this->certificado->certificadoBase64()));

		// SignedInfo é canonicalizado já dentro do documento, como o
		// verificador fará: assim os namespaces em escopo batem.
		$assinatura = $this->certificado->assinar($signedInfo->C14N($exclusiva, false), $this->perfil->algoritmoOpenssl());
		$signatureValue->appendChild($dom->createTextNode(base64_encode($assinatura)));

		$saida = $dom->saveXML();
		if (false === $saida) {
			throw new AssinaturaExcecao('Falha ao serializar o XML assinado.');
		}

		return $saida;
	}

	/**
	 * Verifica integridade da assinatura (digest + SignatureValue) com o
	 * certificado embutido. Não valida cadeia ICP-Brasil nem revogação — isso
	 * a Sefin faz do lado dela.
	 *
	 * @return array{valida:bool,motivo:?string,perfil:?PerfilAssinatura}
	 */
	public static function verificar(string $xml): array
	{
		$dom = self::carregar($xml);
		$sig = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
		if (!$sig instanceof \DOMElement) {
			return ['valida' => false, 'motivo' => 'Sem <Signature>.', 'perfil' => null];
		}
		$get = static function (string $nome) use ($sig): ?\DOMElement {
			$n = $sig->getElementsByTagNameNS(self::NS_DSIG, $nome)->item(0);

			return $n instanceof \DOMElement ? $n : null;
		};
		$signedInfo = $get('SignedInfo');
		$reference = $get('Reference');
		$digestValue = $get('DigestValue');
		$signatureValue = $get('SignatureValue');
		$x509 = $get('X509Certificate');
		$sigMethod = $get('SignatureMethod');
		if (!$signedInfo || !$reference || !$digestValue || !$signatureValue || !$x509 || !$sigMethod) {
			return ['valida' => false, 'motivo' => 'Assinatura incompleta.', 'perfil' => null];
		}
		$perfil = null;
		foreach (PerfilAssinatura::cases() as $p) {
			if ($p->algoritmoAssinatura() === $sigMethod->getAttribute('Algorithm')) {
				$perfil = $p;
			}
		}
		if (null === $perfil) {
			return ['valida' => false, 'motivo' => 'SignatureMethod desconhecido: '.$sigMethod->getAttribute('Algorithm'), 'perfil' => null];
		}
		$exclusiva = $perfil->c14nExclusiva();

		$id = ltrim($reference->getAttribute('URI'), '#');
		$xpath = new \DOMXPath($dom);
		$alvo = $xpath->query(sprintf('//*[@Id=%s]', self::xpathLiteral($id)))?->item(0);
		if (!$alvo instanceof \DOMElement) {
			return ['valida' => false, 'motivo' => "Elemento referenciado '#{$id}' não encontrado.", 'perfil' => $perfil];
		}

		// Transform enveloped: o digest é calculado sem a Signature. Ela é
		// irmã do alvo, então removê-la do documento não muda o alvo — mas
		// removemos mesmo assim, para o caso geral.
		$clone = $sig->parentNode?->removeChild($sig);
		$digestCalc = base64_encode(hash($perfil->hash(), $alvo->C14N($exclusiva, false), true));
		if ($clone instanceof \DOMNode) {
			$alvo->parentNode?->appendChild($clone);
		}
		if (!hash_equals($digestCalc, trim($digestValue->textContent))) {
			return ['valida' => false, 'motivo' => 'DigestValue não confere.', 'perfil' => $perfil];
		}

		$certPem = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $x509->textContent) ?? '', 64, "\n").'-----END CERTIFICATE-----';
		$pub = openssl_pkey_get_public($certPem);
		if (false === $pub) {
			return ['valida' => false, 'motivo' => 'X509Certificate inválido.', 'perfil' => $perfil];
		}
		$assinatura = base64_decode(preg_replace('/\s+/', '', $signatureValue->textContent) ?? '', true);
		if (false === $assinatura) {
			return ['valida' => false, 'motivo' => 'SignatureValue não é Base64.', 'perfil' => $perfil];
		}
		$ok = openssl_verify($signedInfo->C14N($exclusiva, false), $assinatura, $pub, $perfil->algoritmoOpenssl());

		return 1 === $ok
			? ['valida' => true, 'motivo' => null, 'perfil' => $perfil]
			: ['valida' => false, 'motivo' => 'SignatureValue não confere.', 'perfil' => $perfil];
	}

	private static function carregar(string $xml): \DOMDocument
	{
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = false;
		$anterior = libxml_use_internal_errors(true);
		try {
			if (!$dom->loadXML($xml, \LIBXML_NONET | \LIBXML_NOBLANKS)) {
				$e = libxml_get_errors()[0] ?? null;
				throw new AssinaturaExcecao('XML mal formado: '.($e ? trim($e->message) : '?'));
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($anterior);
		}

		return $dom;
	}

	/** @param array<string,string> $atributos */
	private function el(\DOMDocument $dom, string $nome, ?string $texto = null, array $atributos = []): \DOMElement
	{
		$e = $dom->createElementNS(self::NS_DSIG, $nome);
		foreach ($atributos as $k => $v) {
			$e->setAttribute($k, $v);
		}
		if (null !== $texto) {
			$e->appendChild($dom->createTextNode($texto));
		}

		return $e;
	}

	private static function xpathLiteral(string $s): string
	{
		return str_contains($s, "'") ? 'concat(\''.str_replace("'", "',\"'\",'", $s).'\')' : "'{$s}'";
	}
}
