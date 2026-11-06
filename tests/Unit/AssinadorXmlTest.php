<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arijs\NfseNacional\Assinatura\AssinadorXml;
use Arijs\NfseNacional\Assinatura\PerfilAssinatura;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Excecao\AssinaturaExcecao;
use Arijs\NfseNacional\Tests\Support\CertificadoTeste;
use Arijs\NfseNacional\Tests\Support\DpsExemplo;
use Arijs\NfseNacional\Xsd\ValidadorXsd;

final class AssinadorXmlTest extends TestCase
{
	/** @return iterable<string, array{PerfilAssinatura}> */
	public static function perfis(): iterable
	{
		yield 'sha1 (manual oficial)' => [PerfilAssinatura::Sha1];
		yield 'sha256 (exc-c14n)' => [PerfilAssinatura::Sha256];
	}

	/** @dataProvider perfis */
	public function testAssinaEVerificaEContinuaValidoNoXsd(PerfilAssinatura $perfil): void
	{
		$xml = DpsXml::gerar(DpsExemplo::infDps());
		$assinado = (new AssinadorXml(CertificadoTeste::certificado(), $perfil))->assinar($xml, 'infDPS');

		$v = AssinadorXml::verificar($assinado);
		self::assertTrue($v['valida'], (string) $v['motivo']);
		self::assertSame($perfil, $v['perfil']);
		self::assertSame([], (new ValidadorXsd())->validar($assinado, 'DPS'));
	}

	public function testEstruturaDaAssinaturaSegueOPadraoNfse(): void
	{
		$xml = DpsXml::gerar(DpsExemplo::infDps());
		$assinado = (new AssinadorXml(CertificadoTeste::certificado()))->assinar($xml, 'infDPS');

		// Signature sem prefixo, irmã de infDPS, dentro de DPS.
		self::assertMatchesRegularExpression('#</infDPS><Signature xmlns="http://www.w3.org/2000/09/xmldsig\#">#', $assinado);
		self::assertStringEndsWith("</Signature></DPS>\n", $assinado);
		self::assertStringNotContainsString('ds:', $assinado);
		// Referência ao Id, dois transforms na ordem do manual.
		self::assertStringContainsString('<Reference URI="#DPS355030821234567800019500001000000000000042">', $assinado);
		self::assertMatchesRegularExpression('#<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig\#enveloped-signature"/><Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/></Transforms>#', $assinado);
		self::assertStringContainsString('<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>', $assinado);
		self::assertStringContainsString('<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>', $assinado);
		// KeyInfo só com o certificado.
		self::assertMatchesRegularExpression('#<KeyInfo><X509Data><X509Certificate>[A-Za-z0-9+/=]+</X509Certificate></X509Data></KeyInfo>#', $assinado);
		self::assertStringNotContainsString('X509SubjectName', $assinado);
		self::assertStringNotContainsString('KeyValue', $assinado);
	}

	public function testAlteracaoDepoisDeAssinarInvalida(): void
	{
		$xml = DpsXml::gerar(DpsExemplo::infDps());
		$assinado = (new AssinadorXml(CertificadoTeste::certificado()))->assinar($xml, 'infDPS');
		$adulterado = str_replace('<vServ>1500.00</vServ>', '<vServ>15.00</vServ>', $assinado);

		$v = AssinadorXml::verificar($adulterado);
		self::assertFalse($v['valida']);
		self::assertSame('DigestValue não confere.', $v['motivo']);
	}

	public function testRecusaAssinarDuasVezesOuSemId(): void
	{
		$assinador = new AssinadorXml(CertificadoTeste::certificado());
		$assinado = $assinador->assinar(DpsXml::gerar(DpsExemplo::infDps()), 'infDPS');

		try {
			$assinador->assinar($assinado, 'infDPS');
			self::fail('Deveria recusar a segunda assinatura.');
		} catch (AssinaturaExcecao $e) {
			self::assertStringContainsString('já contém', $e->getMessage());
		}

		$this->expectException(AssinaturaExcecao::class);
		$assinador->assinar('<a xmlns="urn:x"><b>1</b></a>', 'b');
	}
}
