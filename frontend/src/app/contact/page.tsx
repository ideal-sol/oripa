import PublicHeader from "../public-header";
import ContactForm from "./contact-form";

export default function ContactPage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <section className="contact-page">
        <div className="contact-head">
          <span className="public-kicker">Contact</span>
          <h1>お問い合わせ</h1>
          <p>ご不明点やご相談はこちらからお送りください。</p>
        </div>
        <ContactForm />
      </section>
    </main>
  );
}
