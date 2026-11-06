<?php
declare (strict_types = 1);

/**
 * 造演示数据：新增内容 + 前台用户
 *
 * 用法：
 *   php scripts/seed_demo_data.php
 *
 * 说明：
 * - 内容走后台 API（POST /api/content），会正常触发标签关联、附件引用和操作日志；
 * - 用户走前台注册 API，注册要图形验证码，脚本用 Captcha::make() 自己造一个再从缓存里
 *   取出明文填进去（验证码存 captcha:{id}，见 app/service/Captcha.php）；
 * - 幂等：标题 / 用户名已存在就跳过，重复跑不会产生重复数据。
 *
 * 依赖：站点已在 http://www.zsk.com 运行（脚本通过 HTTP 调自己的接口）。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();

use app\service\Captcha;
use think\facade\Cache;
use think\facade\Db;

const BASE = 'http://www.zsk.com/api';

function postJson(string $url, array $data, string $token = ''): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    return json_decode($body, true) ?: ['code' => -1, 'msg' => '无响应', 'raw' => $body];
}

// ---------- 管理员登录 ----------
$login = postJson(BASE . '/auth/login', ['username' => 'admin', 'password' => 'admin123']);
if (($login['code'] ?? -1) !== 0) {
    echo "管理员登录失败：{$login['msg']}\n";
    exit(1);
}
$token = $login['data']['token'];
echo "管理员登录成功\n";

// ---------- 内容 ----------
$categoryIds = array_values(
    array_map('intval', Db::name('category')->whereNull('delete_time')->column('id'))
);
if (!$categoryIds) {
    echo "没有可用分类，先去后台建分类\n";
    exit(1);
}

// 第几批：每批 10 条内容 + 10 个前台用户（batch 1 = demo01-10，batch 2 = demo11-20 …）
// 用法：php scripts/seed_demo_data.php 2
$batch = max(1, (int) ($argv[1] ?? 1));

$items = [
    [
        'title' => '端侧大模型落地：手机厂商的算力与隐私权衡',
        'summary' => '把 7B 模型塞进手机，内存、功耗与个人数据不出端，三者很难同时满足。',
        'content' => '<p>端侧推理的最大约束不是算力峰值，而是内存带宽与散热。厂商普遍采用混合方案：意图识别、摘要改写这类轻量任务本地完成，复杂生成再上云。</p><p>隐私是端侧最强的卖点，但模型本身仍要从云端分发更新，权重与用户提示词的边界需要在产品层面讲清楚。</p>',
        'author' => '沈知远', 'tags' => ['大模型', '端侧AI'],
    ],
    [
        'title' => 'Chiplet 走向主流：先进封装如何延续摩尔定律',
        'summary' => '制程红利见顶后，把大芯片拆成小芯粒再用封装拼回去，成了性价比更高的路线。',
        'content' => '<p>Chiplet 的价值不在单颗性能，而在良率与复用：小面积芯粒良率更高，IO 与计算部分可以分别选用合适的制程。</p><p>难点从制造转向了互联标准与封装产能，UCIe 解决了接口，但 2.5D/3D 封装的产能与成本仍是瓶颈。</p>',
        'author' => '陆茸', 'tags' => ['芯片', '先进封装'],
    ],
    [
        'title' => '固态电池量产前夜：三条技术路线的成本账',
        'summary' => '硫化物、氧化物、聚合物各有短板，中试线跑通不等于能装车。',
        'content' => '<p>硫化物离子电导率最高，但对水氧敏感，产线需要全干燥环境，固定资产投资陡增；氧化物稳定性好，界面阻抗是老问题。</p><p>眼下更现实的路线是半固态先行，用现有产线小幅改造换取能量密度与安全的改善。</p>',
        'author' => '秦昭', 'tags' => ['新能源', '固态电池'],
    ],
    [
        'title' => 'GLP-1 之后：减重药物的下半场拼什么',
        'summary' => '从降糖到减重，适应症扩张之后，竞争转向产能、剂型与依从性。',
        'content' => '<p>多肽原料与灌装产能是当前最硬的约束，谁先解决供给，谁就能在医保谈判中占据主动。</p><p>口服剂型与更长给药周期是下一个分水岭，减重是长期用药场景，依从性直接决定真实世界的疗效数据。</p>',
        'author' => '何嘉树', 'tags' => ['生物医药', '创新药'],
    ],
    [
        'title' => '6G 标准博弈：从速率指标到场景定义',
        'summary' => '峰值速率不再是唯一叙事，通感一体与天地融合成了新的提案战场。',
        'content' => '<p>5G 商用暴露的问题不是速率不够，而是垂直行业的商业闭环没跑通，6G 的立项逻辑因此更强调场景。</p><p>通感一体化让基站兼具感知能力，在车路协同与低空经济里有明确需求，也带来了新的频谱与隐私讨论。</p>',
        'author' => '苏映', 'tags' => ['通信', '6G'],
    ],
    [
        'title' => '自动驾驶数据闭环：影子模式还能走多远',
        'summary' => '车队规模决定了数据上限，难例挖掘的效率决定了迭代速度。',
        'content' => '<p>影子模式的核心是低成本拿到"人类怎么开"的对照数据，但数据量增长到一定规模后，瓶颈变成了标注与仿真验证。</p><p>端到端模型让数据利用方式变了：不再只挑接管片段，而是整段轨迹都成为训练素材。</p>',
        'author' => '江维', 'tags' => ['自动驾驶', '数据集'],
    ],
    [
        'title' => '液冷储能电站：安全标准与度电成本',
        'summary' => '热失控防护从电芯层级走到系统层级，液冷成为大容量电站的标配。',
        'content' => '<p>大容量电芯带来更高能量密度，也把散热与一致性问题放大，液冷把温差控制在更小范围，循环寿命随之改善。</p><p>真正决定收益的是度电成本，需要全生命周期去看：初始投资、衰减曲线、运维与残值一起算才准确。</p>',
        'author' => '白鹿鸣', 'tags' => ['储能', '新能源'],
    ],
    [
        'title' => '商业航天的复用竞赛：发射成本还能降多少',
        'summary' => '一级回收已经常态化，下一个成本项是二级与整流罩的复用。',
        'content' => '<p>复用次数的增加并不线性降低成本，检修、翻新与检测占了大头，可靠性认证也需要时间。</p><p>更现实的路径是提高发射频次摊薄固定成本，星座组网带来的批量需求正好提供了这个条件。</p>',
        'author' => '温故', 'tags' => ['商业航天', '运载火箭'],
    ],
    [
        'title' => '工业数字孪生：从可视化到可决策',
        'summary' => '大屏不是终点，孪生体的价值在于能反过来指导生产参数。',
        'content' => '<p>多数项目停在三维可视化阶段，因为机理模型与实时数据对不上，仿真结果无法用于决策。</p><p>可行做法是先做单台设备的孪生，把参数优化跑通，再逐步扩展到产线层级。</p>',
        'author' => '柳青禾', 'tags' => ['数字孪生', '智能制造'],
    ],
    [
        'title' => '开源大模型的商业化边界',
        'summary' => '权重开放不等于能力开放，训练数据、算力与工具链仍是护城河。',
        'content' => '<p>开源模型拉低了应用门槛，但要把能力变成产品，还需要数据清洗、评测体系和推理优化这些工程积累。</p><p>对云厂商而言，开源更像获客手段，真正的收入来自托管推理与配套工具链。</p>',
        'author' => '岳寒', 'tags' => ['开源', '大模型'],
    ],
    // ---------- 第二批（batch 2）----------
    [
        'title' => '低空经济起飞前夜：eVTOL 的适航与空域两道关',
        'summary' => '载人飞行器要上天，先过适航审定，再谈空域协同，两道关都不快。',
        'content' => '<p>适航审定决定型号能否交付，专用条件要逐条谈，周期以年计；空域管控则要和城市空中交通管理平台对接。</p><p>低空飞行服务保障体系目前还在试点，起降点、通信导航监视的配套比飞行器本身更难落地。</p>',
        'author' => '顾行舟', 'tags' => ['低空经济', 'eVTOL'],
    ],
    [
        'title' => '合成生物制造：从实验室菌株到吨级产线',
        'summary' => '菌株性能与发酵放大是两件事，吨级罐的传质和染菌控制才是门槛。',
        'content' => '<p>实验室里的产率数据放大了经常对不上，溶氧、传质、代谢流在吨级罐里完全是另一套逻辑。</p><p>真正拉开差距的是工艺工程团队与下游分离纯化能力，菌株只是入场券。</p>',
        'author' => '唐小满', 'tags' => ['合成生物', '生物制造'],
    ],
    [
        'title' => '算力网络调度：东数西算的"最后一公里"',
        'summary' => '训练任务能西迁，推理任务仍要贴近用户，跨域调度卡在时延与成本上。',
        'content' => '<p>跨域调度依赖网络时延与带宽成本，非实时任务适合西迁，交互型业务搬不动。</p><p>调度平台的难点不在技术，而在计费与结算：算力跨省流动，账怎么算还没有统一答案。</p>',
        'author' => '宋其', 'tags' => ['算力网络', '东数西算'],
    ],
    [
        'title' => '智能座舱摈弃堆屏：交互范式回到驾驶本身',
        'summary' => '屏幕数量不再是卖点，语音与场景化服务的连贯性决定体验上限。',
        'content' => '<p>多屏堆叠带来的信息过载正在被反思，驾驶场景下最重要的是减少操作步骤。</p><p>座舱的竞争从硬件转向操作系统与生态，谁能把车控、导航、娱乐打通，谁就占先。</p>',
        'author' => '闻笛', 'tags' => ['智能座舱', '汽车'],
    ],
    [
        'title' => '氢能重卡商业化：加氢站密度决定生死',
        'summary' => '车辆成本在降，但加氢站建一座亏一座，网络密度不解决就上不了规模。',
        'content' => '<p>重卡路线固定、载重大，理论上最适合氢能，但加氢站的投资回收周期远长于充电站。</p><p>可行的破局点是沿干线走廊布站，靠车队规模锁定利用率，而不是先铺网络再等车。</p>',
        'author' => '骆一鸣', 'tags' => ['氢能', '商用车'],
    ],
    [
        'title' => '半导体设备国产化：清洗先行，光刻仍在爬坡',
        'summary' => '清洗、去胶、CMP 已能批量替代，光刻与量测仍是短板，替代是阶梯式的。',
        'content' => '<p>国产替代按难度递进：清洗、刻蚀、薄膜沉积推进较快，量测与光刻仍在验证阶段。</p><p>设备进厂只是第一步，工艺验证与良率爬坡需要与晶圆厂长期协同，周期以年计。</p>',
        'author' => '岑溪', 'tags' => ['半导体设备', '国产替代'],
    ],
    [
        'title' => '跨境电商小包直邮：政策红利与合规成本',
        'summary' => '免税额度收紧后，小包的成本优势削弱，海外仓与合规申报成为必选项。',
        'content' => '<p>小包直邮靠的是免税额度与低申报成本，政策一收紧，价格优势立刻被侵蚀。</p><p>海外仓能缩短时效但压占资金，卖家要在周转率与履约体验之间重新算账。</p>',
        'author' => '钟离白', 'tags' => ['跨境电商', '供应链'],
    ],
    [
        'title' => '在轨服务：卫星延寿如何变成一门生意',
        'summary' => '延寿服务的客户是保险与运营商，定价取决于在轨资产价值与失效概率。',
        'content' => '<p>在轨服务的技术验证已有多例，商业化的关键是把"延寿几年"折算成可保的风险定价。</p><p>对接、捕获、加注每一步都有失败概率，保险产品不成熟之前，客户很难下决心付费。</p>',
        'author' => '傅青', 'tags' => ['在轨服务', '商业航天'],
    ],
    [
        'title' => '农业无人机：从打药工具到农情数据入口',
        'summary' => '硬件利润薄，真正的价值在作业数据与农资渠道的复用。',
        'content' => '<p>植保无人机保有量已相当可观，单机作业价格战激烈，硬件本身很难赚钱。</p><p>作业轨迹、用药记录沉淀下来就是农情数据，配合农资经销能把一次性买卖变成持续服务。</p>',
        'author' => '季白', 'tags' => ['农业科技', '无人机'],
    ],
    [
        'title' => '医疗影像 AI：三类证之后的定价难题',
        'summary' => '拿证只是开始，医院采购的预算科目与付费方不明确，商业化仍在摸索。',
        'content' => '<p>三类证解决了合规问题，但医院采购要有预算科目，AI 软件该记在设备还是服务里，各地口径不一。</p><p>按例收费的模式在部分省份试点，能否进医保决定了这门生意的天花板。</p>',
        'author' => '阮清', 'tags' => ['医疗AI', '医疗器械'],
    ],
];

echo "\n===== 创建内容 ====\n";
$createdContent = 0;
$skippedContent = 0;

$slice = array_slice($items, ($batch - 1) * 10, 10);

foreach ($slice as $i => $item) {
    if (Db::name('content')->where('title', $item['title'])->count()) {
        echo "  跳过（已存在）：{$item['title']}\n";
        $skippedContent++;
        continue;
    }

    // 最后 2 条留作草稿：让看板「草稿内容」和状态筛选有东西可看
    $status = $i < count($slice) - 2 ? 1 : 0;
    $isPublished = $status === 1;

    $res = postJson(BASE . '/content', [
        'title'       => $item['title'],
        'category_id' => $categoryIds[$i % count($categoryIds)],
        'author'      => $item['author'],
        'summary'     => $item['summary'],
        'content'     => $item['content'],
        'tags'        => $item['tags'],
        'status'      => $status,
        'is_top'      => 0,
        'views'       => mt_rand(120, 3200),
        // 已发布的给一个近期发布时间；草稿不填，发布时后端会自动补
        'publish_time' => $isPublished
            ? date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 12) . ' days'))
            : null,
    ], $token);

    if (($res['code'] ?? -1) === 0) {
        printf("  #%-3d %s  [%s]\n", $res['data']['id'], $item['title'], $isPublished ? '已发布' : '草稿');
        $createdContent++;
    } else {
        echo "  失败：{$item['title']} → {$res['msg']}\n";
    }
}

// ---------- 前台用户 ----------
echo "\n===== 创建前台用户 ====\n";
$createdUser = 0;
$skippedUser = 0;

// 用户名跟着批次走：batch 1 → demo01-10，batch 2 → demo11-20
$userStart = ($batch - 1) * 10 + 1;
for ($i = $userStart; $i < $userStart + 10; $i++) {
    $username = 'demo' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);

    if (Db::name('user')->where('username', $username)->count()) {
        echo "  跳过（已存在）：{$username}\n";
        $skippedUser++;
        continue;
    }

    // 注册要验证码：自己造一个，再从缓存里把明文取出来（key 是 captcha:{id}）
    $made = Captcha::make();
    $code = Cache::get('captcha:' . $made['captcha_id']);

    $res = postJson(BASE . '/front/auth/register', [
        'username'         => $username,
        'password'         => 'demo1234',
        'confirm_password' => 'demo1234',
        'agree'            => 1,
        'captcha_id'       => $made['captcha_id'],
        'captcha_code'     => $code,
    ]);

    if (($res['code'] ?? -1) === 0) {
        echo "  {$username}  注册成功（密码 demo1234）\n";
        $createdUser++;
    } else {
        echo "  {$username}  失败 → {$res['msg']}\n";
    }
}

echo "\n===== 汇总 =====\n";
printf("内容：新增 %d 条，跳过 %d 条\n", $createdContent, $skippedContent);
printf("用户：新增 %d 个，跳过 %d 个\n", $createdUser, $skippedUser);
printf("当前内容总数 %d，注册用户 %d\n",
    Db::name('content')->whereNull('delete_time')->count(),
    Db::name('user')->whereNull('delete_time')->count()
);
