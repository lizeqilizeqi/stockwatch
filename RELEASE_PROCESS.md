# Release Process

本文档用于说明如何给 `stockwatch` 做一次标准发布。

## 1. 发布前检查

- 工作区无意外改动：`git status`
- 页面可访问（域名 + IP）
- 关键接口可用：
  - `/api/stock_info.php?symbol=sz300033`
  - `/api/stock_timeseries.php?symbol=sz300033&mode=minute&datalen=20`

## 2. 生成新版本

### 方式 A：按语义化自动递增

```powershell
scripts\release.bat patch
scripts\release.bat minor
scripts\release.bat major
```

### 方式 B：直接指定版本号

```powershell
scripts\release.bat 0.3.0
```

执行后会自动更新：

- `VERSION`
- `CHANGELOG.md`（将 `Unreleased` 内容转入新版本区块）

## 3. 编辑 Changelog

自动生成后，请手工补充：

- 新功能
- 重要修复
- 不兼容变更（如有）

## 4. 提交并打标签

```powershell
git add .
git commit -m "Release vX.Y.Z"
git tag vX.Y.Z
git push
git push origin vX.Y.Z
```

## 5. GitHub 发布

在 GitHub 仓库创建 Release：

- Tag: `vX.Y.Z`
- 标题：`vX.Y.Z`
- 描述可参考 `.github/release-template.md`

