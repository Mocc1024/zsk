## 二、通用约定

> 本段及之后内容为**手工维护**，源码在 `docs/snippets/api-notes.md`。
> 接口文档由 `scripts/gen_docs.php` 生成，第一章「接口清单」之外的章节全部取自本文件，
> 因此补充说明请写在这里，直接改 `docs/接口文档.md` 会在下次生成时被覆盖。

### 2.1 请求

- 基础路径：`/api`（后台部署在 `/zsk-cms-7c4e/`，由 Nginx 转发到 PHP）
- 编码：`application/json`；上传接口用 `multipart/form-data`
- 鉴权：除 `POST /api/auth/login`、`POST /api/auth/totp/login` 外，全部接口需携带 `Authorization: Bearer <token>`
- 令牌有效期 7200 秒，401 时前端会清令牌并跳转登录页
- 列表接口统一用 `page` / `limit` 分页，返回 `{ list, total, page, pageSize }`。
  筛选字段各列表不同，例如管理员列表支持 `keyword`（账号 / 昵称 / 邮箱模糊匹配）、
  `group_id`、`status`，可组合使用

### 2.2 响应

统一信封：

```json
{ "code": 0, "msg": "操作成功", "data": {} }
```

- `code = 0` 成功；非 0 为业务失败，`msg` 是可直接展示给用户的中文提示
- HTTP 状态码与 `code` 一致：`401` 未登录 / `403` 无权限 / `404` 资源不存在

### 2.3 分页

列表接口统一接受 `page`（默认 1）与 `limit`（默认 10，上限 200），返回：

```json
{ "code": 0, "data": { "list": [], "total": 0, "page": 1, "pageSize": 10 } }
```

### 2.4 常见错误码

| code | 含义 | 常见场景 |
| --- | --- | --- |
| 0 | 成功 | — |
| 1 | 业务失败 | 参数校验不通过、被引用无法删除等 |
| 401 | 未登录/令牌失效 | 令牌过期、被篡改 |
| 403 | 无权限 | 当前用户组未授权该权限点 |
| 404 | 资源不存在 | ID 不存在或已软删除 |
| 500 | 服务异常 | 代码缺陷，需查日志 |

## 三、核心接口示例

### 3.1 登录

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'
```

返回 `data.token`、`data.admin`、`data.permissions`、`data.menus`。

**开启两步验证的账号**：同上请求，但 `data` 里没有 `token`，而是
`{ "needTotp": true, "ticket": "...", "expiresIn": 300 }`。此时必须调第二步换令牌：

```bash
curl -X POST http://127.0.0.1:8000/api/auth/totp/login \
  -H "Content-Type: application/json" \
  -d '{"ticket":"上一步返回的票据","code":"654321"}'
```

票据一次性、5 分钟过期；`code` 为验证器 App 上的 6 位动态码，也可填恢复码（用掉即失效）。
**第一步绝不签发 token**——否则「密码对了但没输验证码」也能拿到令牌，二次验证等于没做。

### 3.2 个人中心

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/auth/profile` | 当前登录管理员信息 + 权限 + 菜单 |
| PUT | `/api/auth/profile` | 修改昵称 / 邮箱 / 头像 |
| POST | `/api/auth/password` | 修改登录密码，需提交 `old_password` |

```bash
curl -X PUT http://127.0.0.1:8000/api/auth/profile \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"nickname":"编辑小张","email":"editor@zsk.com"}'
```

只允许改这三项。登录账号、所属用户组、启用状态**不接受**在这里修改——普通管理员若能自行改组就等于可以提权，
这三项只能由超级管理员在「管理员管理」中操作。传入这几个字段会被忽略。

### 3.3 上传图片（编辑器 / 素材库共用）

```bash
curl -X POST http://127.0.0.1:8000/api/upload/image \
  -H "Authorization: Bearer <token>" \
  -F "file=@a.jpg" -F "folder=editor"
```

大小上限：图片 5MB / 视频 50MB / 附件 20MB（与前端 `UPLOAD_LIMITS` 一致，服务端二次校验）。
`folder` 不存在时自动登记为素材分组。

### 3.4 素材分组

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/attachment/folders` | 分组列表（含每个分组的素材数量）与素材总数 |
| POST | `/api/attachment/folders` | 新增分组（名称唯一，最长 30 字符） |
| PUT | `/api/attachment/folders/:id` | 重命名 / 改排序，重命名会同步改写其下素材 |
| DELETE | `/api/attachment/folders/:id` | 删除分组，其下素材统一移入 `default`（`default` 不可删） |
| PUT | `/api/attachment/move` | 批量移动素材 `{ ids: [1,2], folder: "cover" }` |

### 3.5 回收站

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/content/trash` | 回收站列表 |
| GET | `/api/content/trash/:id` | 回收站内容详情（只读预览，含正文与标签） |
| POST | `/api/content/:id/restore` | 恢复（正文、标签全部保留） |
| POST | `/api/content/:id/force-delete` | 彻底删除（连带标签关联） |

> 内容版本（原 3.5 节的版本列表 / 详情 / 回滚）已于 2026.10.30 下线删除，
> 接口与 `zsk_content_version` 表不再使用，清理脚本见 `database/upgrade_drop_content_version.sql`。

## 四、回归脚本

- `scripts/test_html_filter.php`：XSS 与样式净化回归（39 条用例，双引擎），退出码非 0 即失败
- `scripts/run_sql.php <sql>`：执行迁移 SQL（Windows 无 mysql 客户端时使用）
- `scripts/gen_docs.php`：重新生成本文档、数据库字典与权限清单
- `scripts/measure_edit_efficiency.php`：编辑效率度量
