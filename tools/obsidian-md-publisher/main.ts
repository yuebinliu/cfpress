import { App, Plugin, PluginSettingTab, Setting, Notice, requestUrl, TFile } from 'obsidian';

interface MDPublisherSettings {
  endpoint: string;      // 例如 https://doc.yuebin.uk/wp-json/wp/v2/posts
  username: string;
  appPassword: string;   // 应用密码：Worker 后台「用户 → 🔑 应用密码」生成
  uploadImages: boolean; // 是否自动把本地附件上传到 Worker（POST /wp/v2/media）
  imagePrefix: string;   // 未上传成功时的退回前缀，例如 https://<站点>/wp-content/uploads
}

const DEFAULT_SETTINGS: MDPublisherSettings = {
  endpoint: '',
  username: '',
  appPassword: '',
  uploadImages: true,
  imagePrefix: '',
};

export default class MDPublisher extends Plugin {
  settings: MDPublisherSettings;

  async onload() {
    await this.loadSettings();

    this.addCommand({
      id: 'publish-current-note',
      name: '发布当前笔记 (Markdown 原文)',
      callback: () => this.publishCurrentNote(),
    });

    this.addSettingTab(new MDPublisherSettingTab(this.app, this));
  }

  async publishCurrentNote() {
    const file = this.app.workspace.getActiveFile();
    if (!file || file.extension !== 'md') {
      new Notice('请打开一篇 Markdown 笔记');
      return;
    }
    if (!this.settings.endpoint || !this.settings.username || !this.settings.appPassword) {
      new Notice('请先在插件设置里填写端点、用户名与应用密码');
      return;
    }

    // 1. 读 Markdown 原文（不渲染）
    const raw = await this.app.vault.read(file);

    // 2. frontmatter + 正文
    //    正文里 Obsidian 的 ![[图]] 会：先尝试上传到 Worker 的 /wp-json/wp/v2/media，
    //    用返回的 source_url 替换；上传失败/找不到附件时退回「图片前缀 + 文件名」。
    const cache = this.app.metadataCache.getFileCache(file);
    const fm: any = cache?.frontmatter ?? {};
    const body = await this.resolveWikiImages(this.stripFrontmatter(raw), file.path);

    // 3. 请求体：Worker 端按 content_format 决定存储/渲染路径
    const payload: Record<string, any> = {
      title: fm.title ?? file.basename,
      content: body,
      content_format: 'md',
      status: fm.status ?? 'draft',   // 默认草稿更安全；要直接发布就在 frontmatter 写 status: publish
    };
    if (fm.slug) payload.slug = String(fm.slug);
    if (fm.categories) payload.categories = fm.categories;
    if (fm.tags) payload.tags = fm.tags;

    // 4. 已有 wp_id ⇒ 更新（必须 PUT；新文章 ⇒ POST 到集合端点）
    const existingId = fm.wp_id ? String(fm.wp_id) : '';
    const url = existingId ? `${this.settings.endpoint}/${existingId}` : this.settings.endpoint;
    const method = existingId ? 'PUT' : 'POST';

    try {
      const res = await requestUrl({
        url,
        method,
        headers: {
          'Content-Type': 'application/json',
          // 认证：Basic（用户名 + 应用密码）—— Worker 的 /wp-json/* 只认这套
          Authorization: 'Basic ' + btoa(`${this.settings.username}:${this.settings.appPassword}`),
        },
        body: JSON.stringify(payload),
        throw: false,
      });

      if (res.status >= 200 && res.status < 300) {
        const data = res.json;
        // 5. 新建成功后把返回的 id 写回 frontmatter，下次即走更新
        if (data?.id && !existingId) {
          await this.writeFrontmatterField(file, 'wp_id', String(data.id));
        }
        new Notice(`已提交：${data?.id ?? 'ok'}（${data?.status ?? ''}）`);
      } else {
        new Notice(`发布失败 (${res.status})：${String(res.text ?? '').slice(0, 140)}`);
      }
    } catch (e) {
      new Notice(`请求异常：${String(e).slice(0, 140)}`);
    }
  }

  /** 去掉开头的 --- frontmatter --- 块，只留正文 */
  stripFrontmatter(raw: string): string {
    if (raw.startsWith('---')) {
      const end = raw.indexOf('\n---', 3);
      if (end !== -1) return raw.slice(end + 4).replace(/^\s*\n/, '');
    }
    return raw;
  }

  /** 端点根：把各种写法（…/wp-json/wp/v2/posts、…/wp-json/wp/v2、带尾斜杠）归一成 …/wp-json/wp/v2 */
  apiBase(): string {
    return this.settings.endpoint.trim().replace(/\/+$/, '').replace(/\/posts$/, '').replace(/\/+$/, '');
  }

  /**
   * 处理正文里的 Obsidian 附件语法：
   *   ![[a.jpg]] / ![[folder/a.jpg]] / ![[a.jpg|说明]] / ![[a.jpg#锚点]]
   * 优先上传到 Worker 的 /wp/v2/media 并用返回的 source_url 替换；
   * 上传失败或找不到附件时退回「图片前缀 + 文件名」（前缀未配置则保持原样）。
   * ⚠️ 只处理附件，不处理 [[笔记]] 双链（Worker 端按标准 md 渲染，不认 wiki 语法）。
   */
  async resolveWikiImages(body: string, sourcePath: string): Promise<string> {
    const re = /!\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|([^\]]*))?\]\]/g;
    const hits: { full: string; target: string; alt?: string }[] = [];
    let m: RegExpExecArray | null;
    while ((m = re.exec(body)) !== null) {
      hits.push({ full: m[0], target: String(m[1]).trim(), alt: m[2] });
    }
    if (!hits.length) return body;

    const prefix = (this.settings.imagePrefix || '').trim().replace(/\/+$/, '');
    let out = body;
    let uploaded = 0, fellBack = 0, skipped = 0;
    for (const it of hits) {
      const name = it.target.split('/').pop() || it.target;
      let url = '';
      if (this.settings.uploadImages) {
        url = await this.uploadAttachment(it.target, sourcePath);
        if (url) uploaded++;
      }
      if (!url && prefix) { url = `${prefix}/${encodeURI(name)}`; fellBack++; }
      if (!url) { skipped++; continue; }        // 既没上传也没前缀 ⇒ 原样保留
      const alt = String(it.alt ?? name).replace(/[\[\]]/g, '');
      out = out.split(it.full).join(`![${alt}](${url})`);
    }
    if (hits.length) {
      const msg = `图片：上传 ${uploaded} 张`
        + (fellBack ? `，退回前缀 ${fellBack} 张` : '')
        + (skipped ? `，未处理 ${skipped} 张` : '');
      new Notice(msg);
    }
    return out;
  }

  /** 上传单个附件到 /wp/v2/media（简化形状：原始二进制 + X-Filename）；失败返回空串 */
  async uploadAttachment(linkpath: string, sourcePath: string): Promise<string> {
    if (!this.apiBase() || !this.settings.username || !this.settings.appPassword) return '';
    const f = this.app.metadataCache.getFirstLinkpathDest(linkpath, sourcePath);
    if (!f) {
      new Notice(`找不到附件：${linkpath}`);
      return '';
    }
    try {
      const buf = await this.app.vault.readBinary(f);
      const res = await requestUrl({
        url: `${this.apiBase()}/media?name=${encodeURIComponent(f.name)}`,
        method: 'POST',
        headers: {
          'Content-Type': this.mimeOf(f.extension),
          'X-Filename': encodeURIComponent(f.name),
          Authorization: 'Basic ' + btoa(`${this.settings.username}:${this.settings.appPassword}`),
        },
        body: buf,                              // ArrayBuffer 直传，无需自己拼 multipart
        throw: false,
      });
      if (res.status >= 200 && res.status < 300) {
        const data = res.json;
        if (data && data.source_url) return String(data.source_url);
      }
      new Notice(`图片上传失败 (${res.status})：${f.name}`);
    } catch (e) {
      new Notice(`图片上传异常：${String(e).slice(0, 80)}`);
    }
    return '';
  }

  mimeOf(ext: string): string {
    const e = String(ext || '').toLowerCase();
    if (e === 'png') return 'image/png';
    if (e === 'gif') return 'image/gif';
    if (e === 'webp') return 'image/webp';
    if (e === 'jpg' || e === 'jpeg') return 'image/jpeg';
    return 'application/octet-stream';
  }

  /** 原子写 frontmatter 字段（避免触发多次事件 / 并发丢内容） */
  async writeFrontmatterField(file: TFile, key: string, value: string) {
    await this.app.vault.process(file, (content) => {
      if (content.startsWith('---')) {
        const end = content.indexOf('\n---', 3);
        if (end !== -1) {
          const fmBlock = content.slice(0, end);
          const rest = content.slice(end);
          if (new RegExp(`^${key}:`, 'm').test(fmBlock)) {
            return fmBlock.replace(new RegExp(`^${key}:.*$`, 'm'), `${key}: ${value}`) + rest;
          }
          return `${fmBlock}\n${key}: ${value}${rest}`;
        }
      }
      return `---\n${key}: ${value}\n---\n${content}`;
    });
  }

  async loadSettings() {
    this.settings = Object.assign({}, DEFAULT_SETTINGS, await this.loadData());
  }
  async saveSettings() {
    await this.saveData(this.settings);
  }
}

class MDPublisherSettingTab extends PluginSettingTab {
  plugin: MDPublisher;

  constructor(app: App, plugin: MDPublisher) {
    super(app, plugin);
    this.plugin = plugin;
  }

  display(): void {
    const { containerEl } = this;
    containerEl.empty();

    new Setting(containerEl)
      .setName('端点 URL')
      .setDesc('例如 https://doc.yuebin.uk/wp-json/wp/v2/posts')
      .addText((t) =>
        t.setValue(this.plugin.settings.endpoint).onChange(async (v) => {
          this.plugin.settings.endpoint = v.trim();
          await this.plugin.saveSettings();
        })
      );

    new Setting(containerEl)
      .setName('用户名')
      .setDesc('Worker 后台的用户名（应用密码要由该用户生成）')
      .addText((t) =>
        t.setValue(this.plugin.settings.username).onChange(async (v) => {
          this.plugin.settings.username = v.trim();
          await this.plugin.saveSettings();
        })
      );

    new Setting(containerEl)
      .setName('应用密码')
      .setDesc('Worker 后台「用户 → 🔑 应用密码」生成（明文只显示一次）。认证固定用 Basic。')
      .addText((t) => {
        t.inputEl.type = 'password';
        t.setValue(this.plugin.settings.appPassword).onChange(async (v) => {
          this.plugin.settings.appPassword = v.trim();
          await this.plugin.saveSettings();
        });
      });

    new Setting(containerEl)
      .setName('自动上传图片')
      .setDesc('开启后，笔记里的 ![[图.jpg]] 会先上传到站点（POST /wp-json/wp/v2/media），并用返回的地址替换。')
      .addToggle((t) =>
        t.setValue(this.plugin.settings.uploadImages).onChange(async (v) => {
          this.plugin.settings.uploadImages = v;
          await this.plugin.saveSettings();
        })
      );

    new Setting(containerEl)
      .setName('图片前缀（兜底）')
      .setDesc('上传失败或关闭自动上传时，![[a.jpg]] 会转成 ![a.jpg](前缀/a.jpg)。留空则原样保留。')
      .addText((t) =>
        t.setValue(this.plugin.settings.imagePrefix).onChange(async (v) => {
          this.plugin.settings.imagePrefix = v.trim();
          await this.plugin.saveSettings();
        })
      );
  }
}
