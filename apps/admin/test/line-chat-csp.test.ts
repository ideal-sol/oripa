import { expect, it } from "vitest";
import { NextRequest } from "next/server";
import { proxy } from "@/proxy";

it("permits authenticated blob previews only on the chat list and one detail segment", () => {
  for (const path of ["/line-chat", "/line-chat/conversation-id", "/line-chat/conversation-id/"]) {
    const response = proxy(new NextRequest(`http://localhost${path}`, { headers: { host: "localhost" } }));
    const csp = response.headers.get("content-security-policy")!;
    expect(csp).toMatch(/img-src[^;]*blob:/); expect(csp).toMatch(/media-src[^;]*blob:/);
    expect(csp).toContain("object-src 'none'");
  }
  for (const path of ["/users", "/line-templates", "/line-deliveries", "/settings/line", "/line-chat-extra", "/line-chat/id/other"]) {
    const response = proxy(new NextRequest(`http://localhost${path}`, { headers: { host: "localhost" } }));
    expect(response.headers.get("content-security-policy")).not.toContain("blob:");
  }
});
