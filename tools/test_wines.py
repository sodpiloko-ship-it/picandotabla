"""Pruebas de riesgos editoriales y de publicación del catálogo de vinos."""
import copy
import json
import re
import unittest

import build_wines as wines


class WinePublicationTests(unittest.TestCase):
    def setUp(self):
        self.data = json.loads(wines.SOURCE.read_text(encoding="utf-8"))
        self.tables = json.loads((wines.ROOT / "data/catalogo.json").read_text(encoding="utf-8"))
        self.wine = self.data["wines"][0]

    def validate(self):
        return wines.validate_catalog(self.data, self.tables)

    def test_draft_never_enters_pages_or_discovery(self):
        draft = copy.deepcopy(self.wine)
        draft.update(id="test-draft", slug="draft-must-not-leak", status="draft", name="INTERNAL DRAFT")
        draft["summary"] = ""
        self.data["wines"].append(draft)
        rendered = wines.generated_files(self.data, self.tables)
        self.assertFalse(any("draft-must-not-leak" in str(path) for path in rendered))
        self.assertNotIn("INTERNAL DRAFT", '\n'.join(rendered.values()))
        self.assertNotIn("draft-must-not-leak", '\n'.join(rendered.values()))

    def test_documentary_facts_never_create_fake_review_or_offer(self):
        products = {p["key"]: p for p in self.tables["products"]}
        rendered = wines.render_detail(self.wine, products)
        nodes = [json.loads(block) for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', rendered)]
        self.assertEqual([node["@type"] for node in nodes], ["Article", "BreadcrumbList"])
        self.assertNotIn('"AggregateRating"', rendered)
        self.assertNotIn('"Offer"', rendered)
        self.wine["review"]["rating"] = {"value": 5, "min": 1, "max": 5, "method": "Inventada"}
        with self.assertRaisesRegex(ValueError, "documental"):
            self.validate()

    def test_unknown_table_and_unconfigured_offer_block_publication(self):
        self.wine["pairings"][0]["product_key"] = "does-not-exist"
        with self.assertRaisesRegex(ValueError, "inexistente"):
            self.validate()
        self.wine["pairings"][0]["product_key"] = "dos"
        self.wine["offer"] = {"price_mxn": 199}
        with self.assertRaisesRegex(ValueError, "SKU"):
            self.validate()

    def test_sources_and_dates_are_required_before_publication(self):
        source = self.wine["sources"][0]
        source["url"] = "javascript:alert(1)"
        with self.assertRaisesRegex(ValueError, "HTTPS"):
            self.validate()
        source["url"] = "https://example.org/wine"
        self.wine["review"]["updated_at"] = "1900-01-01"
        with self.assertRaisesRegex(ValueError, "inconsistentes"):
            self.validate()

    def test_slug_traversal_and_duplicate_identity_are_rejected(self):
        self.wine["slug"] = "../../index"
        with self.assertRaisesRegex(ValueError, "slug"):
            self.validate()
        self.wine["slug"] = "valid-slug"
        self.data["wines"].append(copy.deepcopy(self.wine))
        with self.assertRaisesRegex(ValueError, "duplicado"):
            self.validate()

    def test_editorial_content_is_escaped_in_html_and_jsonld(self):
        self.wine["name"] = 'Vino </script><script>alert("x")</script>'
        self.wine["palate"] = '<img src=x onerror=alert(1)>'
        products = {p["key"]: p for p in self.tables["products"]}
        rendered = wines.render_detail(self.wine, products)
        self.assertNotIn('<script>alert("x")</script>', rendered)
        self.assertNotIn('<img src=x onerror=', rendered)
        for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', rendered):
            json.loads(block)

    def test_own_tasting_needs_named_author_notes_and_evidence(self):
        review = self.wine["review"]
        review["kind"] = "tasted"
        with self.assertRaisesRegex(ValueError, "nombre"):
            self.validate()
        review.update(author="Persona de prueba", tasted_at=review["published_at"], tasting_published_at=review["published_at"], tasting_notes="Notas de prueba, nunca publicadas.")
        with self.assertRaisesRegex(ValueError, "evidencia"):
            self.validate()
        review["evidence"] = [{"url": "https://example.org/prueba", "caption": "Evidencia de prueba"}]
        self.validate()

    def test_review_date_and_vintage_must_match_the_actual_tasting(self):
        review = self.wine["review"]
        review.update(kind="tasted", author="Persona de prueba", tasted_at="2026-10-09", tasting_published_at="2026-10-08", tasting_notes="Notas de prueba", evidence=[{"url":"https://example.org/prueba", "caption":"Prueba"}])
        with self.assertRaisesRegex(ValueError, "fechas de cata"):
            self.validate()
        review["tasting_published_at"] = "2026-10-10"
        self.wine["vintage"] = 2024
        review["tasted_vintage"] = 2023
        with self.assertRaisesRegex(ValueError, "añada documentada"):
            self.validate()
        review["tasted_vintage"] = 2024
        review["rating"] = {"value":4,"min":1,"max":5,"method":"Criterio de prueba"}
        self.validate()
        products = {p["key"]: p for p in self.tables["products"]}
        rendered = wines.render_detail(self.wine, products)
        nodes = [json.loads(block) for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', rendered)]
        product = next(node for node in nodes if node["@type"] == "Product")
        self.assertEqual(product["review"]["datePublished"], "2026-10-10")
        self.assertEqual(product["name"], self.wine["name"] + " 2024")
        article = next(node for node in nodes if node["@type"] == "Article")
        self.assertNotIn("url", article["author"])
        self.assertIn("Cata publicada el 10 de octubre de 2026", rendered)

    def test_generation_is_idempotent_and_allows_no_published_wines(self):
        first = wines.generated_files(self.data, self.tables)
        second = wines.generated_files(self.data, self.tables)
        self.assertEqual(first, second)
        for wine in self.data["wines"]:
            wine["status"] = "draft"
        empty = wines.generated_files(self.data, self.tables)
        self.assertIn("Estamos preparando nuevas fichas", empty[wines.ROOT / "vinos/index.html"])
        self.assertFalse(any("/casillero-" in str(path) for path in empty))


if __name__ == "__main__":
    unittest.main()
