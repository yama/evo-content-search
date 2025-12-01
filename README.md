# evo-content-search

## 概要

MODX Evolution用のコンテンツ検索スニペットです。コンテンツ検索に適したインデックスを作成し、キーワード検索を行うことができます。初回アクセス時にインデックスの作成とプラグインの登録を行い、以降は更新を自動的に検知してインデックスを更新します。

## 特長

- インデックスの作成による高速な検索
- `[*content*]` の内容をパースした結果を検索対象にすることができます
- コンテンツ中に含まれるキーワード数や更新日時を考慮した検索結果のソート
- テーマの変更によるカスタマイズ
- セキュリティを考慮した設計

## 動作環境

- MODX Evolution 1.1.1 以降（開発中のため develop ブランチのみ対応）

## インストール手順

1. **ファイルの配置**  
   `ContentSearch` フォルダ内のファイルを `assets/snippets/ContentSearch/` に配置します。
2. **スニペットの作成**  
   管理画面の「スニペット新規作成」に以下を貼り付けます。

   ```php
   return include MODX_BASE_PATH . 'assets/snippets/ContentSearch/bootstrap.php';
   ```
3. **テンプレートへの設置**  
   テンプレートまたはリソースの適当な場所にスニペットコールを配置します。

   ```html
   [[ContentSearch]]
   ```
4. **初回アクセスでのインデックス作成**  
   最初のアクセス時に検索用テーブルとプラグインが自動作成され、サイトのコンテンツをクロールしてインデックスを生成します。初回は管理者でアクセスしておくと、初期化が確実に完了します。

## 使い方

- スニペットコールを配置すると、そこに検索フォームが表示されます。
- 初回アクセス時には検索インデックスの作成が行われるため、少し時間がかかります。
- キーワードを入力して検索ボタンを押すと、検索結果が表示されます。
- `reset=1` をクエリに付与するとインデックスを再作成できます（管理者のみ）。例: `?reset=1`

## スニペットパラメータ

`themes/_default/config.php` の値をベースに、スニペット呼び出し時のパラメータで上書きできます。主なパラメータは以下の通りです。

| パラメータ | 役割 | デフォルト |
| --- | --- | --- |
| `theme` | 使用するテーマ名。`themes/<theme>/config.php` が読み込まれます。 | `_default` |
| `limit` | 1ページあたりの表示件数。 | `10` |
| `mode` | 検索モード。`auto` はキーワード長と InnoDB 設定を見て `fulltext`/`like` を自動判定。 | `auto` |
| `minChars` | 最低入力文字数。未指定時はチェックなし。 | なし |
| `keyword` | キーワードを受け取るクエリパラメータ名。 | `keyword` |
| `basicAuth` | インデックス作成時に使用する Basic 認証情報（`user:pass`）。 | なし |
| `returnResponse` | スニペットの戻り値を出力するかどうか。 | `true` |
| `placeholder-key` | プレースホルダーにセットするキー名。 | `ContentSearch` |
| `additionalKeywordField` | 追加でキーワード化するフィールド名。 | 空文字 |
| `paginateAlwaysShow` | 1ページしかなくてもページネーションを表示するか。 | `false` |
| `css` | インラインで読み込むスタイル。テーマの `template/style.css` が既定。 | テーマ依存 |

## テーマとテンプレートのカスタマイズ

- テーマは `ContentSearch/themes/<theme>/` 配下に配置します。`config.php` で各テンプレートやスタイルを定義します。
- `_default` テーマは以下のテンプレートを持ちます。必要に応じてコピーして編集してください。
  - `template/form.html`：検索フォーム
  - `template/result.html`：検索結果 1 件分の表示
  - `template/admin-widget.html`：管理者向けの再インデックス用ウィジェット
  - `template/style.css`：フォームと結果一覧のスタイル
- 検索結果のラッピングやページネーションは `tplResults` / `tplPaginate` セクションで HTML を差し替えできます。

## 検索モードの挙動

- `fulltext` モード: MySQL/MariaDB の全文検索を利用。タイトルのヒットやキーワード数をスコアに反映します。
- `like` モード: プレーンテキストから部分一致で検索します。短いキーワードで全文検索がヒットしない場合のフォールバックに適しています。
- `auto` モード: `innodb_ft_min_token_size` などの設定値を参照し、キーワードが短い場合は自動的に `like`、それ以外は `fulltext` を使用します。

## インデックスとメンテナンス

- コンテンツ更新は MODX の更新日時を監視して自動的にインデックスへ反映されます。
- `reset=1` を付与してアクセスすると、テーブルの再作成と再クロールを行います（管理者のみ）。
- 再インデックス後はキャッシュがクリアされ、最新の情報が検索に反映されます。

## 開発のヒント

- スニペット本体は `ContentSearch/bootstrap.php`、処理ロジックは `ContentSearch/core/EvoContentSearch.php` にあります。
- テーマの既定設定は `ContentSearch/themes/_default/config.php` で確認できます。開発時はここを基準に追加パラメータを定義すると、ドキュメントと実装の乖離を防げます。
- 検索結果のスコアリングは `EvoContentSearch::generateRelevanceScore()` が担っています。旧バージョン互換用の `generateScore()` は削除済みのため、カスタマイズする場合は `generateRelevanceScore()` を編集してください。
