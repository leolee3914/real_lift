# 真正升降機插件

比較真實的Pocketmine升降機插件

## 許可證 License
本項目以 Server Side Public License (SSPL) 授權，詳情請參考 LICENSE 及 [Server Side Public License FAQ](https://www.mongodb.com/licensing/server-side-public-license/faq)

## 插件使用方法

### 創造升降機
![](https://i.imgur.com/XLIKerA.jpg)
![](https://i.imgur.com/bdNn5IY.jpg)
![](https://i.imgur.com/UfE1i4s.jpg)
如圖所示，在同一直行放置兩個相隔4個空氣的金磚就可以組成升降機，在金磚外圍加入鐵磚可最大支援5x5，無需指令，無需OP權限<br>
升降機只會在空氣或玻璃中上下移動，即上圖中金磚上的木材會防礙升降機移動，使升降機無法上升<br>
<br>
紅石燈能夠使1x1升降機返回該層(高度)，若使用紅石燈，紅石燈必須放置於升降機相鄰的一格，並離地面兩格高<br>
若有多個紅石燈控制同一升降機，升降機會以紅石燈啟動時間的排序前往不同高度<br>
每個紅石燈只能控制一台升降機，若有多台升降機，建議每台升降機最少相隔6個方塊，並放置多個紅石燈<br>
<br>
![](https://i.imgur.com/ovhPLyj.jpg)
若未能使用紅石燈，可改用木牌，木牌必須放置於有效位置，並離地面兩格高<br>
而木牌的第一、二行須依以下方式填寫<br>

第一行: `[lift]` (不區分大小寫)<br>
第二行: `(本層名稱)`<br>
第三行: `fast` (若此行輸入fast，升降機就會在任何情況都使用快速移動模式)<br>
<br>
<br>
![](https://i.imgur.com/yxWW07p.jpg)

上圖為升降機的俯視圖，其中金磚組成1x1升降機，紅石燈表示其有效位置，木材表示木牌的有效位置
<br>
<br>
![](https://i.imgur.com/ZtLlGMq.jpg)

上圖為升降機的俯視圖，其中金磚和鐵磚組成3x3升降機，木材表示木牌的有效位置(不能使用紅石燈)
<br>
<br>
![](https://i.imgur.com/jbYX902.jpg)

上圖為升降機的俯視圖，其中金磚和鐵磚組成5x5升降機，木材表示木牌的有效位置(不能使用紅石燈)

### 使用升降機
1. 點擊紅石燈/木牌(如有)，使升降機返回本層<br>
2. 登上升降機<br>
3. 點擊上/下方構成升降機的金磚，使升降機上/下移動，若有樓層木牌及已開啟選擇樓層功能時，將會彈出樓層選擇表單<br>
4. 升降機到達時會有"叮"的聲響<br>
※如升降機移動時，玩家點擊構成升降機的金磚，升降機會停止移動<br>
※每台升降機能接載多名玩家(如果你能點擊金磚)<br>
※升降機到達後會停留最少2秒<br>
※如果點擊紅石燈/木牌後沒有閃亮、粒子效果或提示，則表示該紅石燈/木牌的擺放位置錯誤或者升降機不合法<br>

***快速移動模式***<br>
在此模式中，升降機會以傳送方式移動到目的地<br>
在以下情況會自動使用此模式:
* 任何一塊樓層木牌上的第三行是fast<br>
* 控制該升降機的紅石燈/木牌被啟動<br>

※若升降機沒有樓層木牌；或玩家在選擇樓層清單中，選擇了**最高層**或**最低層**的話，此模式將被禁止使用

## 配置文件

### config.yml
multiple_floors_mode: bool 是否開啟選擇樓層功能<br>
enable3x3: bool 啟用3x3的升降機 (須先開啟選擇樓層功能)<br>
enable5x5: bool 啟用5x5的升降機；由於性能關係，不建議啟用此選項 (須先開啟選擇樓層功能)<br>
tp_entity: bool 移動升降機中的所有實體(Entity)<br>

## 指令
此插件沒有任何指令

## 真正升降機插件由 Lee Siu San 制作

[https://github.com/leolee3914](https://github.com/leolee3914)<br>
[https://gitlab.com/leolee3914](https://gitlab.com/leolee3914)
