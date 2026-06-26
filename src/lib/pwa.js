export function isIOS() {
  return /iPad|iPhone|iPod/i.test(navigator.userAgent);
}

export function isAndroid() {
  return /Android/i.test(navigator.userAgent);
}

export function isStandalone() {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true
  );
}

export function hasWebPushSupport() {
  return "serviceWorker" in navigator && "PushManager" in window;
}

/** iPhone/iPad в Safari (не с экрана «Домой») — нужна инструкция A2HS. */
export function needsAddToHomeScreenGuide() {
  return isIOS() && !isStandalone();
}

/** Android-телефон/планшет с поддержкой Web Push — показываем текстовую инструкцию. */
export function needsAndroidPushGuide() {
  return isAndroid() && !isIOS() && hasWebPushSupport();
}

export const A2HS_DISMISS_KEY = "notifications-a2hs-dismissed";
export const ANDROID_PUSH_DISMISS_KEY = "notifications-android-push-dismissed";

export function isAddToHomeScreenGuideDismissed() {
  try {
    return sessionStorage.getItem(A2HS_DISMISS_KEY) === "1";
  } catch {
    return false;
  }
}

export function dismissAddToHomeScreenGuide() {
  try {
    sessionStorage.setItem(A2HS_DISMISS_KEY, "1");
  } catch {
    /* ignore */
  }
}

export function isAndroidPushGuideDismissed() {
  try {
    return sessionStorage.getItem(ANDROID_PUSH_DISMISS_KEY) === "1";
  } catch {
    return false;
  }
}

export function dismissAndroidPushGuide() {
  try {
    sessionStorage.setItem(ANDROID_PUSH_DISMISS_KEY, "1");
  } catch {
    /* ignore */
  }
}

export async function hasActivePushSubscription(scope) {
  if (!hasWebPushSupport()) return false;
  try {
    const reg = await navigator.serviceWorker.getRegistration(scope);
    if (!reg) return false;
    const sub = await reg.pushManager.getSubscription();
    return sub !== null;
  } catch {
    return false;
  }
}

export function assetBaseFromConfig(config) {
  const scope = config?.scope || "/";
  return scope.replace(/\/$/, "") || "";
}
