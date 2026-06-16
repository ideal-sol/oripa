import PublicHeader from "../../public-header";
import ProfileClient from "./profile-client";

export default function ProfilePage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <ProfileClient />
    </main>
  );
}
