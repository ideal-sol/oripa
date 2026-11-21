import { LineAdminPage } from "@/components/line/line-admin-page";
import { LineUploadWorkspace } from "@/components/line/line-upload-workspace";

export default function LineUploadsPage() {
  return <LineAdminPage kind="line-uploads" title="LINEアップロード"><LineUploadWorkspace /></LineAdminPage>;
}
